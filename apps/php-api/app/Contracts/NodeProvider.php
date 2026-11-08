<?php

namespace App\Contracts;

interface NodeProvider
{
    public function resourceKey(string $deploymentKey): string;

    /** @return array{state: string, resources?: array<string, mixed>} */
    public function create(object $deployment): array;

    /** @return array{state: string, resources?: array<string, mixed>} */
    public function inspect(object $deployment): array;

    /** @return array{verified: bool, public_ip?: string, checks?: array<int, array<string, mixed>>} */
    public function probe(object $deployment): array;

    /** @return array{state: string, resources?: array<string, mixed>} */
    public function release(object $deployment): array;

    /** @return array{profile: string} */
    public function profile(object $deployment): array;
}
