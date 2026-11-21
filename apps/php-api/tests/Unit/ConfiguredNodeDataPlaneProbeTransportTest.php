<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use App\Services\ConfiguredNodeDataPlaneProbeTransport;
use App\Services\MtlsNodeHealthProbeDriver;
use App\Services\NodeDataPlaneProbeFailure;
use App\Services\NodeDataPlaneProbeTransportException;
use App\Services\NodeHealthProbeDriver;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ConfiguredNodeDataPlaneProbeTransportTest extends TestCase
{
    /** @var list<string> */
    private array $credentialFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->credentialFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_probe_uses_the_configured_node_origin_and_echoes_a_fresh_challenge(): void
    {
        $credentials = $this->credentialFiles();
        $challenge = str_repeat('a1', 32);
        Http::fake(static function (Request $request) {
            return Http::response([
                'data' => [
                    'node_id' => 'node-canary-1',
                    'challenge' => $request->data()['challenge'] ?? null,
                ],
            ]);
        });

        $transport = new ConfiguredNodeDataPlaneProbeTransport(
            [
                'node-canary-1' => [
                    'url' => 'https://node-canary-1.example.test',
                    'ca_bundle' => $credentials['ca_bundle'],
                ],
            ],
            $credentials['client_certificate'],
            $credentials['client_key'],
        );

        $exchange = $transport->exchange('node-canary-1', $challenge, 2500);

        $this->assertTrue($exchange->clientIdentityPresented);
        $this->assertTrue($exchange->mutualTlsEstablished);
        $this->assertTrue($exchange->serverIdentityVerified);
        $this->assertSame('node-canary-1', $exchange->authenticatedNodeId);
        $this->assertTrue($exchange->dataPlaneProbeAccepted);
        $this->assertSame($challenge, $exchange->echoedChallenge);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://node-canary-1.example.test/v1/node/probe'
            && $request->data() === ['challenge' => $challenge]);
        Http::assertSentCount(1);
    }

    public function test_unknown_nodes_and_non_https_or_non_origin_urls_fail_closed(): void
    {
        $credentials = $this->credentialFiles();
        Http::fake();
        $transport = new ConfiguredNodeDataPlaneProbeTransport(
            [
                'http-node' => ['url' => 'http://node.example.test', 'ca_bundle' => $credentials['ca_bundle']],
                'path-node' => ['url' => 'https://node.example.test/probe', 'ca_bundle' => $credentials['ca_bundle']],
            ],
            $credentials['client_certificate'],
            $credentials['client_key'],
        );

        foreach (['missing-node', 'http-node', 'path-node'] as $nodeId) {
            try {
                $transport->exchange($nodeId, str_repeat('b2', 32), 1000);
                $this->fail('Expected invalid endpoint mapping to fail closed.');
            } catch (NodeDataPlaneProbeTransportException $exception) {
                $this->assertSame(NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED, $exception->failureCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_missing_tls_files_do_not_send_a_probe(): void
    {
        Http::fake();
        $transport = new ConfiguredNodeDataPlaneProbeTransport(
            ['node-1' => ['url' => 'https://node-1.example.test', 'ca_bundle' => '/missing/ca.pem']],
            '/missing/client.pem',
            '/missing/client.key',
        );

        try {
            $transport->exchange('node-1', str_repeat('c3', 32), 1000);
            $this->fail('Expected missing mTLS material to fail closed.');
        } catch (NodeDataPlaneProbeTransportException $exception) {
            $this->assertSame(NodeDataPlaneProbeFailure::CLIENT_IDENTITY_UNAVAILABLE, $exception->failureCode);
        }

        Http::assertNothingSent();
    }

    public function test_http_timeout_is_reported_as_timeout_not_mtls_failure(): void
    {
        $credentials = $this->credentialFiles();
        Http::fake(static function () {
            $request = new GuzzleRequest('POST', 'https://node-1.example.test/v1/node/probe');
            $timeout = new NetworkTimeoutException('timeout', $request);

            throw new ConnectionException('connection failed', 0, $timeout);
        });
        $transport = new ConfiguredNodeDataPlaneProbeTransport(
            ['node-1' => ['url' => 'https://node-1.example.test', 'ca_bundle' => $credentials['ca_bundle']]],
            $credentials['client_certificate'],
            $credentials['client_key'],
        );

        try {
            $transport->exchange('node-1', str_repeat('f6', 32), 1000);
            $this->fail('Expected timeout to be surfaced as a fixed timeout code.');
        } catch (NodeDataPlaneProbeTransportException $exception) {
            $this->assertSame(NodeDataPlaneProbeFailure::TIMEOUT, $exception->failureCode);
        }
    }

    public function test_probe_driver_is_only_bound_for_explicit_http_transport_configuration(): void
    {
        config(['node_health_probe.transport' => 'disabled']);
        $this->app->forgetInstance(MtlsNodeHealthProbeDriver::class);
        $this->app->forgetInstance(NodeHealthProbeDriver::class);
        $this->assertFalse($this->app->bound(NodeHealthProbeDriver::class));

        config(['node_health_probe.transport' => 'http']);
        (new AppServiceProvider($this->app))->register();

        $this->assertInstanceOf(
            MtlsNodeHealthProbeDriver::class,
            $this->app->make(NodeHealthProbeDriver::class),
        );
    }

    public function test_rejected_or_mismatched_probe_response_never_reports_accepted(): void
    {
        $credentials = $this->credentialFiles();
        Http::fake(static function (Request $request) {
            $challenge = (string) ($request->data()['challenge'] ?? '');
            $nodeMismatch = str_contains($request->url(), 'node-mismatch.example.test');

            return Http::response(['data' => [
                'node_id' => $nodeMismatch ? 'other-node' : 'challenge-mismatch',
                'challenge' => $nodeMismatch ? $challenge : str_repeat('e5', 32),
            ]]);
        });
        $transport = new ConfiguredNodeDataPlaneProbeTransport(
            [
                'node-mismatch' => ['url' => 'https://node-mismatch.example.test', 'ca_bundle' => $credentials['ca_bundle']],
                'challenge-mismatch' => ['url' => 'https://challenge-mismatch.example.test', 'ca_bundle' => $credentials['ca_bundle']],
            ],
            $credentials['client_certificate'],
            $credentials['client_key'],
        );

        $nodeMismatch = (new MtlsNodeHealthProbeDriver($transport))->probe('node-mismatch');
        $this->assertFalse($nodeMismatch->succeeded);
        $this->assertSame(NodeDataPlaneProbeFailure::NODE_IDENTITY_MISMATCH, $nodeMismatch->failureCode);

        $challengeMismatch = (new MtlsNodeHealthProbeDriver($transport))->probe('challenge-mismatch');
        $this->assertFalse($challengeMismatch->succeeded);
        $this->assertSame(NodeDataPlaneProbeFailure::PROBE_ECHO_MISMATCH, $challengeMismatch->failureCode);
    }

    /** @return array{client_certificate: string, client_key: string, ca_bundle: string} */
    private function credentialFiles(): array
    {
        $files = [];
        foreach (['client_certificate', 'client_key', 'ca_bundle'] as $name) {
            $path = tempnam(sys_get_temp_dir(), 'xlb-probe-test-');
            $this->assertNotFalse($path);
            file_put_contents($path, 'test-only tls path placeholder');
            $this->credentialFiles[] = $path;
            $files[$name] = $path;
        }

        return $files;
    }
}
