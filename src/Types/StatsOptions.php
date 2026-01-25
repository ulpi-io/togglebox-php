<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class StatsOptions
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $batchSize = 20,
        public readonly int $flushIntervalMs = 10000,
        public readonly int $maxRetries = 3,
    ) {
    }
}
