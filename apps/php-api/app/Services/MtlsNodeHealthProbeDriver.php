<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class MtlsNodeHealthProbeDriver implements NodeHealthProbeDriver
{
    public function __construct(
        private readonly NodeDataPlaneProbeTransport $transport,
        private readonly int $timeoutMs = 5000,
    ) {
        if ($timeoutMs < 1 || $timeoutMs > 30_000) {
            throw new InvalidArgumentException('Node data-plane probe timeout is invalid.');
        }
    }

    public function probe(string $nodeId): NodeHealthProbeResult
    {
        if (trim($nodeId) === '') {
            throw new InvalidArgumentException('Node ID is required for data-plane probing.');
        }

        try {
            $challenge = bin2hex(random_bytes(32));
        } catch (Throwable) {
            return $this->failed(NodeDataPlaneProbeFailure::TRANSPORT_ERROR);
        }

        $startedAt = hrtime(true);

        try {
            $exchange = $this->transport->exchange($nodeId, $challenge, $this->timeoutMs);
        } catch (NodeDataPlaneProbeTransportException $exception) {
            return $this->failed($exception->failureCode);
        } catch (Throwable) {
            return $this->failed(NodeDataPlaneProbeFailure::TRANSPORT_ERROR);
        }

        if (! $exchange->clientIdentityPresented) {
            return $this->failed(NodeDataPlaneProbeFailure::CLIENT_IDENTITY_UNAVAILABLE);
        }

        if (! $exchange->mutualTlsEstablished) {
            return $this->failed(NodeDataPlaneProbeFailure::MTLS_HANDSHAKE_FAILED);
        }

        if (! $exchange->serverIdentityVerified || $exchange->authenticatedNodeId === null) {
            return $this->failed(NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED);
        }

        if (! hash_equals($nodeId, $exchange->authenticatedNodeId)) {
            return $this->failed(NodeDataPlaneProbeFailure::NODE_IDENTITY_MISMATCH);
        }

        if (! $exchange->dataPlaneProbeAccepted) {
            return $this->failed(NodeDataPlaneProbeFailure::DATA_PLANE_PROBE_REJECTED);
        }

        if ($exchange->echoedChallenge === null || ! hash_equals($challenge, $exchange->echoedChallenge)) {
            return $this->failed(NodeDataPlaneProbeFailure::PROBE_ECHO_MISMATCH);
        }

        $latencyMs = max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return new NodeHealthProbeResult(true, $this->observedAt(), latencyMs: $latencyMs);
    }

    private function failed(string $failureCode): NodeHealthProbeResult
    {
        if (! in_array($failureCode, NodeDataPlaneProbeFailure::all(), true)) {
            $failureCode = NodeDataPlaneProbeFailure::TRANSPORT_ERROR;
        }

        return new NodeHealthProbeResult(false, $this->observedAt(), failureCode: $failureCode);
    }

    private function observedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
