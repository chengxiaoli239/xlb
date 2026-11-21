<?php

namespace Tests\Unit;

use App\Services\MtlsNodeHealthProbeDriver;
use App\Services\NodeDataPlaneProbeExchange;
use App\Services\NodeDataPlaneProbeFailure;
use App\Services\NodeDataPlaneProbeTransport;
use App\Services\NodeDataPlaneProbeTransportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MtlsNodeHealthProbeDriverTest extends TestCase
{
    public function test_success_requires_verified_mtls_node_identity_and_echoed_data_plane_challenge(): void
    {
        $transport = new class implements NodeDataPlaneProbeTransport
        {
            public string $nodeId = '';

            public string $challenge = '';

            public int $timeoutMs = 0;

            public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange
            {
                $this->nodeId = $nodeId;
                $this->challenge = $challenge;
                $this->timeoutMs = $timeoutMs;

                return new NodeDataPlaneProbeExchange(true, true, true, $nodeId, true, $challenge);
            }
        };

        $result = (new MtlsNodeHealthProbeDriver($transport, 3210))->probe('node-1');

        $this->assertTrue($result->succeeded);
        $this->assertSame('node-1', $transport->nodeId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $transport->challenge);
        $this->assertSame(3210, $transport->timeoutMs);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
        $this->assertNull($result->failureCode);
    }

    public function test_process_health_without_a_challenge_echo_is_not_healthy(): void
    {
        $transport = new class implements NodeDataPlaneProbeTransport
        {
            public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange
            {
                return new NodeDataPlaneProbeExchange(true, true, true, $nodeId, true, null);
            }
        };

        $result = (new MtlsNodeHealthProbeDriver($transport))->probe('node-1');

        $this->assertFalse($result->succeeded);
        $this->assertSame(NodeDataPlaneProbeFailure::PROBE_ECHO_MISMATCH, $result->failureCode);
        $this->assertNull($result->latencyMs);
    }

    public function test_missing_or_invalid_mtls_and_probe_evidence_fails_closed(): void
    {
        $invalidExchanges = [
            'missing client identity' => [new NodeDataPlaneProbeExchange(false, true, true, 'node-1', true, 'challenge'), NodeDataPlaneProbeFailure::CLIENT_IDENTITY_UNAVAILABLE],
            'failed mTLS handshake' => [new NodeDataPlaneProbeExchange(true, false, true, 'node-1', true, 'challenge'), NodeDataPlaneProbeFailure::MTLS_HANDSHAKE_FAILED],
            'unverified server identity' => [new NodeDataPlaneProbeExchange(true, true, false, 'node-1', true, 'challenge'), NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED],
            'missing server identity' => [new NodeDataPlaneProbeExchange(true, true, true, null, true, 'challenge'), NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED],
            'wrong node identity' => [new NodeDataPlaneProbeExchange(true, true, true, 'other-node', true, 'challenge'), NodeDataPlaneProbeFailure::NODE_IDENTITY_MISMATCH],
            'rejected data-plane probe' => [new NodeDataPlaneProbeExchange(true, true, true, 'node-1', false, 'challenge'), NodeDataPlaneProbeFailure::DATA_PLANE_PROBE_REJECTED],
            'missing echo' => [new NodeDataPlaneProbeExchange(true, true, true, 'node-1', true, null), NodeDataPlaneProbeFailure::PROBE_ECHO_MISMATCH],
            'wrong echo' => [new NodeDataPlaneProbeExchange(true, true, true, 'node-1', true, 'other-challenge'), NodeDataPlaneProbeFailure::PROBE_ECHO_MISMATCH],
        ];

        foreach ($invalidExchanges as $case => [$exchange, $failureCode]) {
            $transport = new class($exchange) implements NodeDataPlaneProbeTransport
            {
                public function __construct(private readonly NodeDataPlaneProbeExchange $exchange) {}

                public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange
                {
                    return $this->exchange;
                }
            };

            $result = (new MtlsNodeHealthProbeDriver($transport))->probe('node-1');

            $this->assertFalse($result->succeeded, $case);
            $this->assertSame($failureCode, $result->failureCode, $case);
        }
    }

    public function test_timeout_failure_maps_to_a_fixed_non_sensitive_code(): void
    {
        $transport = new class implements NodeDataPlaneProbeTransport
        {
            public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange
            {
                throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::TIMEOUT);
            }
        };

        $result = (new MtlsNodeHealthProbeDriver($transport))->probe('node-1');

        $this->assertFalse($result->succeeded);
        $this->assertSame(NodeDataPlaneProbeFailure::TIMEOUT, $result->failureCode);
    }

    public function test_unexpected_transport_exception_is_sanitized(): void
    {
        $transport = new class implements NodeDataPlaneProbeTransport
        {
            public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange
            {
                throw new RuntimeException('client_key=/private/path/node.key secret-value');
            }
        };

        $result = (new MtlsNodeHealthProbeDriver($transport))->probe('node-1');
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertFalse($result->succeeded);
        $this->assertSame(NodeDataPlaneProbeFailure::TRANSPORT_ERROR, $result->failureCode);
        $this->assertStringNotContainsString('private/path', $serialized);
        $this->assertStringNotContainsString('secret-value', $serialized);
    }
}
