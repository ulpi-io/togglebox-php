<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class ClientOptions
{
    public function __construct(
        public readonly string $platform,
        public readonly string $environment,
        public readonly ?string $apiUrl = null,
        public readonly ?string $tenantSubdomain = null,
        public readonly ?string $apiKey = null,
        public readonly ?CacheOptions $cache = null,
        public readonly string $configVersion = 'stable',
        public readonly ?StatsOptions $stats = null,
    ) {
    }

    public function getApiUrl(): string
    {
        if ($this->tenantSubdomain) {
            return "https://{$this->tenantSubdomain}.togglebox.io";
        }

        return $this->apiUrl ?? '';
    }
}
