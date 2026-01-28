<?php

declare(strict_types=1);

namespace ToggleBox\Types;

class Experiment
{
    public function __construct(
        public readonly string $experimentKey,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $hypothesis,
        public readonly string $status,
        public readonly array $variations,
        public readonly string $controlVariation,
        public readonly array $trafficAllocation,
        public readonly ?array $targeting,
        public readonly array $primaryMetric,
        public readonly ?array $secondaryMetrics,
        public readonly float $confidenceLevel,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        // SECURITY: Added schedule fields for proper time-based validation
        public readonly ?string $scheduledStartAt = null,
        public readonly ?string $scheduledEndAt = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            experimentKey: $data['experimentKey'],
            name: $data['name'],
            description: $data['description'] ?? null,
            hypothesis: $data['hypothesis'],
            status: $data['status'],
            variations: $data['variations'],
            controlVariation: $data['controlVariation'],
            trafficAllocation: $data['trafficAllocation'],
            targeting: $data['targeting'] ?? null,
            primaryMetric: $data['primaryMetric'],
            secondaryMetrics: $data['secondaryMetrics'] ?? null,
            confidenceLevel: $data['confidenceLevel'] ?? 0.95,
            startedAt: $data['startedAt'] ?? null,
            completedAt: $data['completedAt'] ?? null,
            createdAt: $data['createdAt'],
            updatedAt: $data['updatedAt'],
            scheduledStartAt: $data['scheduledStartAt'] ?? null,
            scheduledEndAt: $data['scheduledEndAt'] ?? null,
        );
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if experiment is within its scheduled time window.
     * Returns true if no schedule is set or if current time is within window.
     */
    public function isWithinSchedule(): bool
    {
        $now = new \DateTimeImmutable();

        if ($this->scheduledStartAt !== null) {
            $startTime = new \DateTimeImmutable($this->scheduledStartAt);
            if ($startTime > $now) {
                return false; // Not started yet
            }
        }

        if ($this->scheduledEndAt !== null) {
            $endTime = new \DateTimeImmutable($this->scheduledEndAt);
            if ($endTime < $now) {
                return false; // Already ended
            }
        }

        return true;
    }
}
