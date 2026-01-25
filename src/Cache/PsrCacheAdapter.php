<?php

declare(strict_types=1);

namespace ToggleBox\Cache;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

/**
 * Adapter for PSR-16 SimpleCache implementations.
 */
class PsrCacheAdapter implements CacheInterface
{
    public function __construct(
        private readonly PsrCacheInterface $cache,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($this->normalizeKey($key));
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->cache->set($this->normalizeKey($key), $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($this->normalizeKey($key));
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    private function normalizeKey(string $key): string
    {
        // Replace characters not allowed in PSR-16 keys
        return preg_replace('/[{}()\/\\\\@:]/', '_', $key) ?? $key;
    }
}
