<?php

declare(strict_types=1);

namespace ToggleBox\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    public function delete(string $key): void;

    public function clear(): void;
}
