<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class ExperimentContext
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $country = null,
        public readonly ?string $language = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'userId' => $this->userId,
            'country' => $this->country,
            'language' => $this->language,
        ], fn($v) => $v !== null);
    }
}
