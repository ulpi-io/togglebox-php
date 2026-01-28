# ToggleBox PHP SDK

Official PHP SDK for [ToggleBox](https://togglebox.io) - Remote Config, Feature Flags, and A/B Experiments.

## Installation

```bash
composer require togglebox/sdk
```

## Quick Start

```php
use ToggleBox\ToggleBoxClient;
use ToggleBox\Types\ClientOptions;
use ToggleBox\Types\FlagContext;
use ToggleBox\Types\ExperimentContext;

// Initialize the client
$client = new ToggleBoxClient(new ClientOptions(
    platform: 'web',
    environment: 'production',
    apiUrl: 'https://api.yourdomain.com', // Self-hosted
    // OR for cloud:
    // tenantSubdomain: 'your-tenant',
    apiKey: 'your-api-key', // Optional for self-hosted
));
```

## Tier 1: Remote Configs

Remote configs provide the same value to everyone - perfect for feature toggles, API URLs, and app settings.

```php
// Get a single config value with type-safe default
$apiUrl = $client->getConfigValue('api_url', 'https://default.api.com');
$maxRetries = $client->getConfigValue('max_retries', 3);

// Get all active config parameters as key-value array
$allConfigs = $client->getAllConfigs();
// Returns: ['api_url' => 'https://...', 'max_retries' => 5, ...]
```

## Tier 2: Feature Flags (2-Value Model)

Feature flags support targeting by country and language with a simple 2-value model (A/B).

```php
$context = new FlagContext(
    userId: 'user-123',
    country: 'US',
    language: 'en',
);

// Get flag result with full details
$result = $client->getFlag('new-checkout-ui', $context);
echo $result->value;       // The actual value (valueA or valueB)
echo $result->servedValue; // 'A' or 'B'
echo $result->reason;      // 'targeting_match', 'rollout', etc.

// Simple boolean check
if ($client->isFlagEnabled('dark-mode', $context)) {
    enableDarkMode();
}

// Get flag metadata without evaluation
$flagInfo = $client->getFlagInfo('dark-mode');
if ($flagInfo !== null) {
    echo $flagInfo->flagKey;     // 'dark-mode'
    echo $flagInfo->name;        // 'Dark Mode'
    echo $flagInfo->enabled;     // true/false
    echo $flagInfo->valueA;      // Value A
    echo $flagInfo->valueB;      // Value B
}
```

## Tier 3: A/B Experiments

Full multi-variant experiments with traffic allocation and conversion tracking.

```php
$context = new ExperimentContext(
    userId: 'user-123',
    country: 'US',
    language: 'en',
);

// Get the user's assigned variation
$variant = $client->getVariant('checkout-redesign', $context);

if ($variant !== null) {
    echo $variant->variationKey;  // 'control', 'variant_1', etc.
    echo $variant->variationName; // 'Control', 'Variant 1', etc.
    echo $variant->value;         // The variation's value (JSON or string)
    echo $variant->isControl;     // true/false
}

// Track a conversion
use ToggleBox\Types\ConversionData;

$client->trackConversion('checkout-redesign', $context, new ConversionData(
    metricName: 'purchase',
    value: 99.99, // Optional: for sum/average metrics
));

// Track a custom event
// Note: When both experimentKey and variationKey are provided,
// this also tracks a conversion event for experiment analytics.
$client->trackEvent('page_view', $context, [
    'experimentKey' => 'checkout-redesign', // Optional: ties event to experiment
    'variationKey' => 'variant_1',          // Optional: required if experimentKey is set
    'properties' => [                       // Optional: additional event data
        'page' => '/checkout',
        'referrer' => 'google.com',
    ],
]);

// Get experiment metadata without assignment
$experimentInfo = $client->getExperimentInfo('checkout-redesign');
if ($experimentInfo !== null) {
    echo $experimentInfo->experimentKey; // 'checkout-redesign'
    echo $experimentInfo->name;          // 'Checkout Redesign'
    echo $experimentInfo->status;        // 'running', 'draft', 'completed'
    print_r($experimentInfo->variations); // Array of variations
}

// Flush stats to server (call at end of request)
$client->flushStats();
```

## Caching

By default, the SDK uses an in-memory array cache that persists for the lifetime of the request. For better performance across requests, provide a PSR-16 compatible cache:

```php
use ToggleBox\Cache\PsrCacheAdapter;

// Using Symfony Cache
$symfonyCache = new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter($redis)
);

$client = new ToggleBoxClient(
    new ClientOptions(
        platform: 'web',
        environment: 'production',
        apiUrl: 'https://api.yourdomain.com',
        cache: new CacheOptions(
            enabled: true,
            ttl: 300, // 5 minutes
        ),
    ),
    new PsrCacheAdapter($symfonyCache),
);
```

## Manual Cache Refresh

```php
// Force refresh all cached data
$client->refresh();

// Clear all caches
$client->clearCache();
```

## Health Check

```php
// Check API connectivity
try {
    $health = $client->checkConnection();
    echo $health['status']; // 'ok'
} catch (\ToggleBox\Exceptions\ToggleBoxException $e) {
    // API is unreachable
    log_error('ToggleBox API is down: ' . $e->getMessage());
}
```

## Error Handling

```php
use ToggleBox\Exceptions\ToggleBoxException;
use ToggleBox\Exceptions\NetworkException;
use ToggleBox\Exceptions\ConfigurationException;

try {
    $variant = $client->getVariant('my-experiment', $context);
} catch (NetworkException $e) {
    // API request failed
    log_error('ToggleBox API error: ' . $e->getMessage());
} catch (ToggleBoxException $e) {
    // Other SDK errors (e.g., experiment not found)
    log_error('ToggleBox error: ' . $e->getMessage());
}
```

## Configuration Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `platform` | string | Yes | Platform name (e.g., 'web', 'mobile') |
| `environment` | string | Yes | Environment name (e.g., 'production', 'staging') |
| `apiUrl` | string | * | API base URL (for self-hosted) |
| `tenantSubdomain` | string | * | Tenant subdomain (for cloud) |
| `apiKey` | string | No | API key for authentication |
| `cache` | CacheOptions | No | Cache configuration |
| `configVersion` | string | No | Default config version ('stable', 'latest', or specific) |

\* Either `apiUrl` or `tenantSubdomain` is required, but not both.

## Requirements

- PHP 8.1 or higher
- Guzzle HTTP client

## License

MIT
