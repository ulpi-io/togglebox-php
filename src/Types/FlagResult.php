<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class FlagResult
{
    public function __construct(
        public readonly string $flagKey,
        public readonly mixed $value,
        public readonly string $servedValue, // 'A' or 'B'
        public readonly string $reason,
    ) {
    }
}
