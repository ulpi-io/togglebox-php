<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class ConversionData
{
    public function __construct(
        public readonly string $metricName,
        public readonly ?float $value = null,
    ) {
    }
}
