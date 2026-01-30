<?php

declare(strict_types=1);

namespace ToggleBox;

use ToggleBox\Cache\ArrayCache;
use ToggleBox\Cache\CacheInterface;
use ToggleBox\Exceptions\ConfigurationException;
use ToggleBox\Exceptions\ToggleBoxException;
use ToggleBox\Http\HttpClient;
use ToggleBox\Types\ClientOptions;
use ToggleBox\Types\ConversionData;
use ToggleBox\Types\Experiment;
use ToggleBox\Types\ExperimentContext;
use ToggleBox\Types\Flag;
use ToggleBox\Types\FlagContext;
use ToggleBox\Types\FlagResult;
use ToggleBox\Types\VariantAssignment;

/**
 * ToggleBox Client for PHP
 *
 * Unified client for the three-tier architecture:
 * - Tier 1: Remote Configs (same value for everyone)
 * - Tier 2: Feature Flags (2-value, country/language targeting)
 * - Tier 3: Experiments (multi-variant A/B testing)
 */
class ToggleBoxClient
{
    private HttpClient $http;
    private CacheInterface $cache;
    private string $platform;
    private string $environment;
    private int $cacheTtl;
    private bool $cacheEnabled;

    /** @var array<array{type: string, timestamp: string, ...}> */
    private array $pendingEvents = [];

    /** Maximum pending events to prevent unbounded memory growth */
    private int $maxQueueSize = 1000;

    /** Batch size before auto-flushing events */
    private int $statsBatchSize = 20;

    /** Maximum retry attempts for failed stats flushes */
    private int $statsMaxRetries = 3;

    public function __construct(
        ClientOptions $options,
        ?CacheInterface $cache = null,
    ) {
        if (empty($options->platform) || empty($options->environment)) {
            throw new ConfigurationException(
                'Missing required options: platform and environment are required'
            );
        }

        if (empty($options->apiUrl) && empty($options->tenantSubdomain)) {
            throw new ConfigurationException(
                'Either apiUrl or tenantSubdomain must be provided'
            );
        }

        if (!empty($options->apiUrl) && !empty($options->tenantSubdomain)) {
            throw new ConfigurationException(
                'Cannot provide both apiUrl and tenantSubdomain - use one or the other'
            );
        }

        $this->platform = $options->platform;
        $this->environment = $options->environment;
        $this->cacheTtl = $options->cache?->ttl ?? 300;
        // SECURITY: Check cache enabled flag (default true if not set)
        $this->cacheEnabled = $options->cache?->enabled ?? true;
        $this->http = new HttpClient($options->getApiUrl(), $options->apiKey);
        $this->cache = $cache ?? new ArrayCache();

        // Read stats options
        $this->statsBatchSize = $options->stats?->batchSize ?? 20;
        $this->statsMaxRetries = $options->stats?->maxRetries ?? 3;
        $this->maxQueueSize = $options->stats?->maxQueueSize ?? 1000;
    }

    // ==================== TIER 1: REMOTE CONFIGS ====================

    /**
     * Get a remote config value.
     *
     * @template T
     * @param string $key Config key
     * @param T $defaultValue Default value if not found
     * @return T The config value or default
     */
    public function getConfigValue(string $key, mixed $defaultValue = null): mixed
    {
        try {
            $config = $this->getConfig();
            return $config[$key] ?? $defaultValue;
        } catch (ToggleBoxException) {
            return $defaultValue;
        }
    }

    /**
     * Get all config values.
     *
     * @return array<string, mixed>
     * @throws ToggleBoxException
     */
    public function getAllConfigs(): array
    {
        return $this->getConfig();
    }

    /**
     * Get all active config parameters as key-value object.
     *
     * Firebase-style individual parameters. Each parameter has its own version history,
     * but the API returns only active versions as a simple key-value object.
     *
     * @return array<string, mixed>
     * @throws ToggleBoxException
     */
    public function getConfig(): array
    {
        $cacheKey = "config:{$this->platform}:{$this->environment}";

        // SECURITY: Only use cache if enabled
        if ($this->cacheEnabled) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/configs";
        $response = $this->http->get($path);
        $config = $response['data'] ?? [];

        // Only cache if enabled
        if ($this->cacheEnabled) {
            $this->cache->set($cacheKey, $config, $this->cacheTtl);
        }

        return $config;
    }

    // ==================== TIER 2: FEATURE FLAGS (2-value) ====================

    /**
     * Get a feature flag value (2-value model).
     *
     * @throws ToggleBoxException
     */
    public function getFlag(string $flagKey, FlagContext $context): FlagResult
    {
        $flags = $this->getFlags();
        $flag = null;

        foreach ($flags as $f) {
            if ($f->flagKey === $flagKey) {
                $flag = $f;
                break;
            }
        }

        if ($flag === null) {
            throw new ToggleBoxException("Flag \"{$flagKey}\" not found");
        }

        $result = $this->evaluateFlag($flag, $context);

        // Queue exposure event
        // Note: API schema expects 'value' key (not 'servedValue')
        $this->queueEvent('flag_evaluation', [
            'flagKey' => $flagKey,
            'value' => $result->servedValue,
            'userId' => $context->userId,
            'country' => $context->country,
            'language' => $context->language,
        ]);

        return $result;
    }

    /**
     * Check if a feature flag is enabled (boolean).
     */
    public function isFlagEnabled(string $flagKey, FlagContext $context, bool $defaultValue = false): bool
    {
        try {
            $result = $this->getFlag($flagKey, $context);
            return $result->servedValue === 'A';
        } catch (ToggleBoxException) {
            return $defaultValue;
        }
    }

    /**
     * Get all feature flags.
     *
     * @return Flag[]
     * @throws ToggleBoxException
     */
    public function getFlags(): array
    {
        $cacheKey = "flags:{$this->platform}:{$this->environment}";

        // SECURITY: Only use cache if enabled
        if ($this->cacheEnabled) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/flags";
        $response = $this->http->get($path);

        $flags = array_map(
            fn(array $data) => Flag::fromArray($data),
            $response['data'] ?? []
        );

        // Only cache if enabled
        if ($this->cacheEnabled) {
            $this->cache->set($cacheKey, $flags, $this->cacheTtl);
        }

        return $flags;
    }

    /**
     * Get a specific flag's metadata without evaluation.
     *
     * @param string $flagKey The flag key to retrieve
     * @return Flag|null The flag metadata or null if not found
     */
    public function getFlagInfo(string $flagKey): ?Flag
    {
        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/flags/{$flagKey}";
        try {
            $response = $this->http->get($path);
            return Flag::fromArray($response['data']);
        } catch (ToggleBoxException) {
            return null;
        }
    }

    // ==================== TIER 3: EXPERIMENTS ====================

    /**
     * Get the assigned variation for an experiment.
     *
     * @throws ToggleBoxException
     */
    public function getVariant(string $experimentKey, ExperimentContext $context): ?VariantAssignment
    {
        $experiments = $this->getExperiments();
        $experiment = null;

        foreach ($experiments as $exp) {
            if ($exp->experimentKey === $experimentKey) {
                $experiment = $exp;
                break;
            }
        }

        if ($experiment === null) {
            throw new ToggleBoxException("Experiment \"{$experimentKey}\" not found");
        }

        $assignment = $this->assignVariation($experiment, $context);

        if ($assignment !== null) {
            // Queue exposure event
            $this->queueEvent('experiment_exposure', [
                'experimentKey' => $experimentKey,
                'variationKey' => $assignment->variationKey,
                'userId' => $context->userId,
            ]);
        }

        return $assignment;
    }

    /**
     * Track a conversion event for an experiment.
     *
     * Note: Uses getVariantWithoutTracking to avoid inflating exposure stats.
     * Conversions should not add extra exposures.
     *
     * @throws ToggleBoxException
     */
    public function trackConversion(
        string $experimentKey,
        ExperimentContext $context,
        ConversionData $data,
    ): void {
        // Use no-track variant to avoid inflating exposure stats
        $assignment = $this->getVariantWithoutTracking($experimentKey, $context);

        if ($assignment !== null) {
            $this->queueEvent('conversion', [
                'experimentKey' => $experimentKey,
                'metricName' => $data->metricName,
                'variationKey' => $assignment->variationKey,
                'userId' => $context->userId,
                'value' => $data->value,
            ]);
        }
    }

    /**
     * Get the assigned variation without tracking an exposure.
     *
     * Useful for conversion tracking where you need the assignment
     * but don't want to inflate exposure stats.
     *
     * @throws ToggleBoxException
     */
    public function getVariantWithoutTracking(string $experimentKey, ExperimentContext $context): ?VariantAssignment
    {
        $experiments = $this->getExperiments();
        $experiment = null;

        foreach ($experiments as $exp) {
            if ($exp->experimentKey === $experimentKey) {
                $experiment = $exp;
                break;
            }
        }

        if ($experiment === null) {
            throw new ToggleBoxException("Experiment \"{$experimentKey}\" not found");
        }

        return $this->assignVariation($experiment, $context);
    }

    /**
     * Get all experiments.
     *
     * @return Experiment[]
     * @throws ToggleBoxException
     */
    public function getExperiments(): array
    {
        $cacheKey = "experiments:{$this->platform}:{$this->environment}";

        // SECURITY: Only use cache if enabled
        if ($this->cacheEnabled) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/experiments";
        $response = $this->http->get($path);

        $experiments = array_map(
            fn(array $data) => Experiment::fromArray($data),
            $response['data'] ?? []
        );

        // Only cache if enabled
        if ($this->cacheEnabled) {
            $this->cache->set($cacheKey, $experiments, $this->cacheTtl);
        }

        return $experiments;
    }

    /**
     * Get a specific experiment's metadata without assignment.
     *
     * @param string $experimentKey The experiment key to retrieve
     * @return Experiment|null The experiment metadata or null if not found
     */
    public function getExperimentInfo(string $experimentKey): ?Experiment
    {
        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/experiments/{$experimentKey}";
        try {
            $response = $this->http->get($path);
            return Experiment::fromArray($response['data']);
        } catch (ToggleBoxException) {
            return null;
        }
    }

    // ==================== CACHE & LIFECYCLE ====================

    /**
     * Manually refresh all cached data.
     *
     * @throws ToggleBoxException
     */
    public function refresh(): void
    {
        $this->cache->delete("config:{$this->platform}:{$this->environment}");
        $this->cache->delete("flags:{$this->platform}:{$this->environment}");
        $this->cache->delete("experiments:{$this->platform}:{$this->environment}");

        $this->getConfig();
        $this->getFlags();
        $this->getExperiments();
    }

    /**
     * Flush pending stats events to the server with retry logic.
     */
    public function flushStats(): void
    {
        if (empty($this->pendingEvents)) {
            return;
        }

        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/stats/events";

        for ($attempt = 0; $attempt < $this->statsMaxRetries; $attempt++) {
            try {
                $this->http->post($path, ['events' => $this->pendingEvents]);
                $this->pendingEvents = [];
                return;
            } catch (ToggleBoxException $e) {
                // Last attempt - give up silently (stats are not critical)
                if ($attempt === $this->statsMaxRetries - 1) {
                    return;
                }
                // Exponential backoff: 1s, 2s, 4s...
                usleep((int)(1000 * pow(2, $attempt) * 1000));
            }
        }
    }

    /**
     * Clear all caches.
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * Check API connectivity and service health.
     *
     * @return array{status: string, uptime?: int}
     * @throws ToggleBoxException If API is unreachable
     */
    public function checkConnection(): array
    {
        return $this->http->get('/api/v1/health');
    }

    // ==================== PRIVATE HELPERS ====================

    private function evaluateFlag(Flag $flag, FlagContext $context): FlagResult
    {
        // Helper to get value by served indicator
        $getValue = fn(string $which): mixed => $which === 'A' ? $flag->valueA : $flag->valueB;

        // Helper to get default served value
        $getDefaultServed = fn(): string => $flag->defaultValue ?? 'B';

        // 1) Disabled → return defaultValue (not always A)
        if (!$flag->enabled) {
            $served = $getDefaultServed();
            return new FlagResult(
                flagKey: $flag->flagKey,
                value: $getValue($served),
                servedValue: $served,
                reason: 'flag_disabled',
            );
        }

        // Get force arrays from targeting (API sends them under targeting, not top-level)
        $forceExcludeUsers = $flag->targeting['forceExcludeUsers'] ?? [];
        $forceIncludeUsers = $flag->targeting['forceIncludeUsers'] ?? [];

        // 2) forceExclude → B (excluded from feature/treatment, gets control)
        if (!empty($forceExcludeUsers) && in_array($context->userId, $forceExcludeUsers, true)) {
            return new FlagResult(
                flagKey: $flag->flagKey,
                value: $getValue('B'),
                servedValue: 'B',
                reason: 'force_excluded',
            );
        }

        // 3) forceInclude → A (included in feature/treatment)
        if (!empty($forceIncludeUsers) && in_array($context->userId, $forceIncludeUsers, true)) {
            return new FlagResult(
                flagKey: $flag->flagKey,
                value: $getValue('A'),
                servedValue: 'A',
                reason: 'force_included',
            );
        }

        // 4) Check targeting (country/language)
        if ($flag->targeting !== null) {
            $countries = $flag->targeting['countries'] ?? [];

            // If country targeting exists but user has no country, serve default
            if (!empty($countries) && $context->country === null) {
                $served = $getDefaultServed();
                return new FlagResult(
                    flagKey: $flag->flagKey,
                    value: $getValue($served),
                    servedValue: $served,
                    reason: 'country_not_targeted',
                );
            }

            if (!empty($countries) && $context->country !== null) {
                $matchedCountry = false;

                foreach ($countries as $countryTarget) {
                    if (strtoupper($countryTarget['country']) === strtoupper($context->country)) {
                        $matchedCountry = true;

                        // Check language targeting within country
                        $languages = $countryTarget['languages'] ?? [];
                        if (!empty($languages)) {
                            // Languages configured but context.language is null → exclude
                            if ($context->language === null) {
                                $served = $getDefaultServed();
                                return new FlagResult(
                                    flagKey: $flag->flagKey,
                                    value: $getValue($served),
                                    servedValue: $served,
                                    reason: 'language_not_targeted',
                                );
                            }

                            $matchedLangTarget = null;
                            foreach ($languages as $langTarget) {
                                if (strtolower($langTarget['language']) === strtolower($context->language)) {
                                    $matchedLangTarget = $langTarget;
                                    break;
                                }
                            }

                            if ($matchedLangTarget === null) {
                                // Language doesn't match → return defaultValue (not A)
                                $served = $getDefaultServed();
                                return new FlagResult(
                                    flagKey: $flag->flagKey,
                                    value: $getValue($served),
                                    servedValue: $served,
                                    reason: 'language_not_targeted',
                                );
                            }

                            // Language matches → use language target's serveValue
                            $served = $matchedLangTarget['serveValue'] ?? 'A';
                            return new FlagResult(
                                flagKey: $flag->flagKey,
                                value: $getValue($served),
                                servedValue: $served,
                                reason: 'targeting_match',
                            );
                        }

                        // Country matches (no language targeting) → use country target's serveValue
                        $served = $countryTarget['serveValue'] ?? 'A';
                        return new FlagResult(
                            flagKey: $flag->flagKey,
                            value: $getValue($served),
                            servedValue: $served,
                            reason: 'targeting_match',
                        );
                    }
                }

                if (!$matchedCountry) {
                    // Country doesn't match → return defaultValue (not A)
                    $served = $getDefaultServed();
                    return new FlagResult(
                        flagKey: $flag->flagKey,
                        value: $getValue($served),
                        servedValue: $served,
                        reason: 'country_not_targeted',
                    );
                }
            }
        }

        // 5) Rollout only if enabled
        if ($flag->rolloutEnabled) {
            $hash = crc32($context->userId . ':' . $flag->flagKey);
            $percentage = abs($hash % 100);

            $thresholdA = $flag->rolloutPercentageA;
            $served = $percentage < $thresholdA ? 'A' : 'B';
            return new FlagResult(
                flagKey: $flag->flagKey,
                value: $getValue($served),
                servedValue: $served,
                reason: 'rollout',
            );
        }

        // 6) Default fallback when rollout is disabled
        $served = $getDefaultServed();
        return new FlagResult(
            flagKey: $flag->flagKey,
            value: $getValue($served),
            servedValue: $served,
            reason: 'default',
        );
    }

    private function assignVariation(Experiment $experiment, ExperimentContext $context): ?VariantAssignment
    {
        // Only running experiments assign variations
        if (!$experiment->isRunning()) {
            return null;
        }

        // SECURITY: Check if experiment is within its scheduled time window
        if (!$experiment->isWithinSchedule()) {
            return null;
        }

        // Check targeting
        if ($experiment->targeting !== null) {
            // Check force exclude
            $excludeUsers = $experiment->targeting['forceExcludeUsers'] ?? [];
            if (in_array($context->userId, $excludeUsers, true)) {
                return null;
            }

            // Note: forceIncludeUsers only ensures the user is included in the experiment.
            // They still go through normal hash-based allocation (no special treatment).
            // The check is implicitly handled by not returning null for them.

            // Check country targeting
            $countries = $experiment->targeting['countries'] ?? [];

            // If countries are defined and no country in context → exclude
            if (!empty($countries) && $context->country === null) {
                return null;
            }

            if (!empty($countries) && $context->country !== null) {
                $matchedCountry = false;

                foreach ($countries as $countryTarget) {
                    if (strtoupper($countryTarget['country']) === strtoupper($context->country)) {
                        $matchedCountry = true;

                        // Check language targeting
                        $languages = $countryTarget['languages'] ?? [];

                        // If languages are defined and no language in context → exclude
                        if (!empty($languages) && $context->language === null) {
                            return null;
                        }

                        if (!empty($languages) && $context->language !== null) {
                            $matchedLanguage = false;
                            foreach ($languages as $langTarget) {
                                if (strtolower($langTarget['language']) === strtolower($context->language)) {
                                    $matchedLanguage = true;
                                    break;
                                }
                            }

                            if (!$matchedLanguage) {
                                return null; // Language doesn't match
                            }
                        }
                        break;
                    }
                }

                if (!$matchedCountry) {
                    return null; // Country doesn't match
                }
            }
        }

        // Hash-based variation assignment
        $hash = crc32($context->userId . ':' . $experiment->experimentKey);
        $bucket = abs($hash % 100);

        $cumulative = 0;
        foreach ($experiment->trafficAllocation as $allocation) {
            $cumulative += $allocation['percentage'];
            if ($bucket < $cumulative) {
                // Find the variation
                foreach ($experiment->variations as $variation) {
                    if ($variation['key'] === $allocation['variationKey']) {
                        return VariantAssignment::fromArray($experiment->experimentKey, [
                            'variationKey' => $variation['key'],
                            'variationName' => $variation['name'],
                            'value' => $variation['value'],
                            'isControl' => $variation['isControl'] ?? ($variation['key'] === $experiment->controlVariation),
                        ]);
                    }
                }
            }
        }

        return null;
    }

    private function queueEvent(string $eventType, array $data): void
    {
        // Enforce max queue size to prevent unbounded memory growth
        if (count($this->pendingEvents) >= $this->maxQueueSize) {
            // Drop oldest event to make room
            array_shift($this->pendingEvents);
        }

        // Flatten event structure to match expected API format
        // Merge type and timestamp with data instead of nesting
        $this->pendingEvents[] = array_merge(
            ['type' => $eventType, 'timestamp' => date('c')],
            $data
        );

        // Auto-flush when batch size is reached
        if (count($this->pendingEvents) >= $this->statsBatchSize) {
            $this->flushStats();
        }
    }
}
