<?php

declare(strict_types=1);

namespace ToggleBox\Cache;

/**
 * Simple in-memory array cache for single-request scenarios.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $cache = [];

    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        $item = $this->cache[$key];

        if ($item['expires'] < time()) {
            unset($this->cache[$key]);
            return null;
        }

        return $item['value'];
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->cache[$key] = [
            'value' => $value,
            'expires' => time() + $ttl,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->cache[$key]);
    }

    public function clear(): void
    {
        $this->cache = [];
    }
}
