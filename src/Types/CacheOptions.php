<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class CacheOptions
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $ttl = 300, // 5 minutes in seconds
    ) {
    }
}
