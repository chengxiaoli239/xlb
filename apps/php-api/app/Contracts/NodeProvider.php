<?php

namespace App\Contracts;

interface NodeProvider
{
    public function resourceKey(string $deploymentKey): string;

    /**
     * Repeated calls for the same deployment key must return the same owned resource set.
     *
     * @return array{state: 'building'|'bootstrapping'|'ready', resources?: array<string, mixed>}
     */
    public function create(object $deployment): array;

    /**
     * @return array{state: 'building'|'bootstrapping'|'ready'|'deleting'|'failed'|'released', resources?: array<string, mixed>}
     */
    public function inspect(object $deployment): array;

    /** @return array{verified: bool, public_ip?: string, checks?: array<int, array<string, mixed>>} */
    public function probe(object $deployment): array;

    /**
     * Repeated calls must be safe; `released` is only valid after owned billable resources are absent.
     *
     * @return array{state: 'deleting'|'released', resources?: array<string, mixed>}
     */
    public function release(object $deployment): array;

    /** @return array{profile: string} */
    public function profile(object $deployment): array;
}
