<?php

namespace App\Services;

interface NodeDataPlaneProbeTransport
{
    /**
     * Exchange a fresh challenge over a mutually authenticated data-plane connection.
     *
     * Implementations must validate the server certificate and expected node identity,
     * present the configured client identity, and only report an accepted probe after
     * the data plane echoes the challenge. A process-health endpoint is not sufficient.
     *
     * @throws NodeDataPlaneProbeTransportException
     */
    public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange;
}
