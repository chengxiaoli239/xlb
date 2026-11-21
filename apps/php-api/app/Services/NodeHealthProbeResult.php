<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class NodeHealthProbeResult
{
    public function __construct(
        public bool $succeeded,
        public DateTimeImmutable $observedAt,
        public ?int $latencyMs = null,
        public ?float $packetLossPct = null,
        public ?string $failureCode = null,
    ) {
        if (($latencyMs !== null && $latencyMs < 0)
            || ($packetLossPct !== null && (! is_finite($packetLossPct) || $packetLossPct < 0 || $packetLossPct > 100))) {
            throw new InvalidArgumentException('Node health probe metrics are invalid.');
        }

        if (($succeeded && ($latencyMs === null || $failureCode !== null))
            || (! $succeeded && ($failureCode === null || trim($failureCode) === ''))) {
            throw new InvalidArgumentException('Node health probe outcome is invalid.');
        }
    }
}
