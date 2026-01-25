<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class VariantAssignment
{
    public function __construct(
        public readonly string $experimentKey,
        public readonly string $variationKey,
        public readonly string $variationName,
        public readonly mixed $value,
        public readonly bool $isControl,
    ) {
    }

    public static function fromArray(string $experimentKey, array $data): self
    {
        return new self(
            experimentKey: $experimentKey,
            variationKey: $data['variationKey'],
            variationName: $data['variationName'] ?? $data['variationKey'],
            value: $data['value'],
            isControl: $data['isControl'] ?? false,
        );
    }
}
