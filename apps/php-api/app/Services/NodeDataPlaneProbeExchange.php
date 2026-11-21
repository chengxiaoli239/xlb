<?php

namespace App\Services;

final readonly class NodeDataPlaneProbeExchange
{
    public function __construct(
        public bool $clientIdentityPresented,
        public bool $mutualTlsEstablished,
        public bool $serverIdentityVerified,
        public ?string $authenticatedNodeId,
        public bool $dataPlaneProbeAccepted,
        public ?string $echoedChallenge,
    ) {}
}
