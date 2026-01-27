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
    private string $configVersion;
    private int $cacheTtl;

    /** @var array<string, array{eventType: string, data: array}> */
    private array $pendingEvents = [];

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
        $this->configVersion = $options->configVersion;
        $this->cacheTtl = $options->cache?->ttl ?? 300;
        $this->http = new HttpClient($options->getApiUrl(), $options->apiKey);
        $this->cache = $cache ?? new ArrayCache();
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
     * Get configuration using the configured version (default: 'stable').
     *
     * @return array<string, mixed>
     * @throws ToggleBoxException
     */
    public function getConfig(): array
    {
        return $this->getConfigVersion($this->configVersion);
    }

    /**
     * Get a specific config version.
     *
     * @return array<string, mixed>
     * @throws ToggleBoxException
     */
    public function getConfigVersion(string $version): array
    {
        $cacheKey = "config:{$this->platform}:{$this->environment}:{$version}";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $path = match ($version) {
            'stable' => "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/versions/latest/stable",
            'latest' => "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/versions/latest",
            default => "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/versions/{$version}",
        };

        $response = $this->http->get($path);
        $config = $response['data']['config'] ?? [];

        $this->cache->set($cacheKey, $config, $this->cacheTtl);

        return $config;
    }

    /**
     * List all configuration versions for the current platform/environment.
     *
     * @return array<array{version: string, isStable: bool, createdAt: string}>
     * @throws ToggleBoxException
     */
    public function getConfigVersions(): array
    {
        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/versions";
        $response = $this->http->get($path);
        return $response['data'] ?? [];
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
        $this->queueEvent('flag_evaluation', [
            'flagKey' => $flagKey,
            'servedValue' => $result->servedValue,
            'userId' => $context->userId,
            'country' => $context->country,
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

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/flags";
        $response = $this->http->get($path);

        $flags = array_map(
            fn(array $data) => Flag::fromArray($data),
            $response['data'] ?? []
        );

        $this->cache->set($cacheKey, $flags, $this->cacheTtl);

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
     * @throws ToggleBoxException
     */
    public function trackConversion(
        string $experimentKey,
        ExperimentContext $context,
        ConversionData $data,
    ): void {
        $assignment = $this->getVariant($experimentKey, $context);

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
     * Get all experiments.
     *
     * @return Experiment[]
     * @throws ToggleBoxException
     */
    public function getExperiments(): array
    {
        $cacheKey = "experiments:{$this->platform}:{$this->environment}";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/experiments";
        $response = $this->http->get($path);

        $experiments = array_map(
            fn(array $data) => Experiment::fromArray($data),
            $response['data'] ?? []
        );

        $this->cache->set($cacheKey, $experiments, $this->cacheTtl);

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

    /**
     * Track a custom event.
     *
     * @param string $eventName Name of the event
     * @param ExperimentContext $context User context
     * @param array|null $data Optional event data with 'experimentKey', 'variationKey', 'properties'
     */
    public function trackEvent(string $eventName, ExperimentContext $context, ?array $data = null): void
    {
        $this->queueEvent('custom_event', [
            'eventName' => $eventName,
            'userId' => $context->userId,
            'experimentKey' => $data['experimentKey'] ?? null,
            'variationKey' => $data['variationKey'] ?? null,
            'properties' => $data['properties'] ?? [],
            'country' => $context->country,
            'language' => $context->language,
        ]);
    }

    // ==================== CACHE & LIFECYCLE ====================

    /**
     * Manually refresh all cached data.
     *
     * @throws ToggleBoxException
     */
    public function refresh(): void
    {
        $this->cache->delete("config:{$this->platform}:{$this->environment}:{$this->configVersion}");
        $this->cache->delete("flags:{$this->platform}:{$this->environment}");
        $this->cache->delete("experiments:{$this->platform}:{$this->environment}");

        $this->getConfig();
        $this->getFlags();
        $this->getExperiments();
    }

    /**
     * Flush pending stats events to the server.
     */
    public function flushStats(): void
    {
        if (empty($this->pendingEvents)) {
            return;
        }

        try {
            $path = "/api/v1/platforms/{$this->platform}/environments/{$this->environment}/stats/events";
            $this->http->post($path, ['events' => $this->pendingEvents]);
            $this->pendingEvents = [];
        } catch (ToggleBoxException) {
            // Silently fail - stats are not critical
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
        // If flag is disabled, always return valueA
        if (!$flag->enabled) {
            return new FlagResult(
                flagKey: $flag->flagKey,
                value: $flag->valueA,
                servedValue: 'A',
                reason: 'flag_disabled',
            );
        }

        // Check targeting
        if ($flag->targeting !== null) {
            $countries = $flag->targeting['countries'] ?? [];

            if (!empty($countries) && $context->country !== null) {
                $matchedCountry = false;

                foreach ($countries as $countryTarget) {
                    if (strtoupper($countryTarget['country']) === strtoupper($context->country)) {
                        $matchedCountry = true;

                        // Check language targeting within country
                        $languages = $countryTarget['languages'] ?? [];
                        if (!empty($languages) && $context->language !== null) {
                            $matchedLanguage = false;
                            foreach ($languages as $langTarget) {
                                if (strtolower($langTarget['language']) === strtolower($context->language)) {
                                    $matchedLanguage = true;
                                    break;
                                }
                            }

                            if (!$matchedLanguage) {
                                return new FlagResult(
                                    flagKey: $flag->flagKey,
                                    value: $flag->valueA,
                                    servedValue: 'A',
                                    reason: 'language_not_targeted',
                                );
                            }
                        }

                        // Country matches, return valueB
                        return new FlagResult(
                            flagKey: $flag->flagKey,
                            value: $flag->valueB,
                            servedValue: 'B',
                            reason: 'targeting_match',
                        );
                    }
                }

                if (!$matchedCountry) {
                    return new FlagResult(
                        flagKey: $flag->flagKey,
                        value: $flag->valueA,
                        servedValue: 'A',
                        reason: 'country_not_targeted',
                    );
                }
            }
        }

        // Default: use hash-based rollout
        $hash = crc32($context->userId . ':' . $flag->flagKey);
        $percentage = abs($hash % 100);

        // Default 50/50 split
        if ($percentage < 50) {
            return new FlagResult(
                flagKey: $flag->flagKey,
                value: $flag->valueA,
                servedValue: 'A',
                reason: 'rollout',
            );
        }

        return new FlagResult(
            flagKey: $flag->flagKey,
            value: $flag->valueB,
            servedValue: 'B',
            reason: 'rollout',
        );
    }

    private function assignVariation(Experiment $experiment, ExperimentContext $context): ?VariantAssignment
    {
        // Only running experiments assign variations
        if (!$experiment->isRunning()) {
            return null;
        }

        // Check targeting
        if ($experiment->targeting !== null) {
            // Check force exclude
            $excludeUsers = $experiment->targeting['forceExcludeUsers'] ?? [];
            if (in_array($context->userId, $excludeUsers, true)) {
                return null;
            }

            // Check force include - assign to control
            $includeUsers = $experiment->targeting['forceIncludeUsers'] ?? [];
            if (in_array($context->userId, $includeUsers, true)) {
                foreach ($experiment->variations as $variation) {
                    if ($variation['key'] === $experiment->controlVariation) {
                        return VariantAssignment::fromArray($experiment->experimentKey, [
                            'variationKey' => $variation['key'],
                            'variationName' => $variation['name'],
                            'value' => $variation['value'],
                            'isControl' => true,
                        ]);
                    }
                }
            }

            // Check country targeting
            $countries = $experiment->targeting['countries'] ?? [];
            if (!empty($countries) && $context->country !== null) {
                $matchedCountry = false;

                foreach ($countries as $countryTarget) {
                    if (strtoupper($countryTarget['country']) === strtoupper($context->country)) {
                        $matchedCountry = true;

                        // Check language targeting
                        $languages = $countryTarget['languages'] ?? [];
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
        $this->pendingEvents[] = [
            'eventType' => $eventType,
            'data' => $data,
            'timestamp' => date('c'),
        ];
    }
}
