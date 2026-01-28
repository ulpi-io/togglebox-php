<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class Flag
{
    public function __construct(
        public readonly string $flagKey,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $enabled,
        public readonly string $flagType,
        public readonly mixed $valueA,
        public readonly mixed $valueB,
        public readonly ?array $targeting,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        // SECURITY: Added missing fields for proper flag evaluation
        public readonly mixed $defaultValue = null,
        public readonly bool $rolloutEnabled = false,
        public readonly float $rolloutPercentageA = 50.0,
        public readonly float $rolloutPercentageB = 50.0,
        public readonly ?array $forceIncludeUsers = null,
        public readonly ?array $forceExcludeUsers = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            flagKey: $data['flagKey'],
            name: $data['name'],
            description: $data['description'] ?? null,
            enabled: $data['enabled'],
            flagType: $data['flagType'] ?? 'boolean',
            valueA: $data['valueA'],
            valueB: $data['valueB'],
            targeting: $data['targeting'] ?? null,
            createdAt: $data['createdAt'],
            updatedAt: $data['updatedAt'],
            defaultValue: $data['defaultValue'] ?? null,
            rolloutEnabled: $data['rolloutEnabled'] ?? false,
            rolloutPercentageA: (float) ($data['rolloutPercentageA'] ?? 50.0),
            rolloutPercentageB: (float) ($data['rolloutPercentageB'] ?? 50.0),
            forceIncludeUsers: $data['forceIncludeUsers'] ?? null,
            forceExcludeUsers: $data['forceExcludeUsers'] ?? null,
        );
    }
}
