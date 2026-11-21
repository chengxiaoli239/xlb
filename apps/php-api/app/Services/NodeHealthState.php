<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class NodeHealthState
{
    public function __construct(
        public string $status,
        public int $failureStreak,
        public int $successStreak,
        public ?DateTimeImmutable $lastProbeAt = null,
    ) {
        if (! in_array($status, ['healthy', 'unhealthy'], true) || $failureStreak < 0 || $successStreak < 0) {
            throw new InvalidArgumentException('Node health state is invalid.');
        }
    }
}
