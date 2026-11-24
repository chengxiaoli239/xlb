<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use App\Services\ConfiguredNodeDataPlaneProbeTransport;
use App\Services\DatabaseNodeHealthStateStore;
use App\Services\MtlsNodeHealthProbeDriver;
use App\Services\NodeDataPlaneProbeFailure;
use App\Services\NodeDataPlaneProbeTransportException;
use App\Services\NodeHealthProbeDriver;
use App\Services\NodeHealthState;
use App\Services\NodeRoutingPolicy;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ConfiguredNodeDataPlaneProbeTransportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $credentialFiles = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->credentialFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
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

    public function test_probe_performs_a_real_tls_13_mutual_tls_exchange_with_the_configured_credentials(): void
    {
        if (DB::getDefaultConnection() !== 'sqlite') {
            $this->markTestSkipped('The forked loopback TLS test is covered by the SQLite CI job to avoid sharing a database socket across processes.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('stream_socket_server')) {
            $this->markTestSkipped('pcntl, POSIX signals, and PHP stream sockets are required for the local mTLS integration test.');
        }

        $credentials = $this->mutualTlsCredentialFiles();
        $challenge = str_repeat('d4', 32);
        $nodeId = 'node-mtls-loopback';
        $reportPath = $credentials['directory'].'/server-report.json';
        $context = stream_context_create(['ssl' => [
            'local_cert' => $credentials['server_certificate'],
            'local_pk' => $credentials['server_key'],
            'verify_peer' => true,
            'verify_peer_name' => false,
            'allow_self_signed' => false,
            'cafile' => $credentials['ca_bundle'],
            'capture_peer_cert' => true,
        ]]);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $this->assertNotFalse($listener, sprintf('Could not bind local TLS test listener: %s (%d)', $errorMessage, $errorCode));
        $this->assertTrue(is_resource($listener));
        $listenerAddress = stream_socket_get_name($listener, false);
        $this->assertIsString($listenerAddress);
        $port = (int) substr((string) strrchr($listenerAddress, ':'), 1);

        $serverPid = pcntl_fork();
        $this->assertNotSame(-1, $serverPid, 'Could not fork the local TLS test server.');
        if ($serverPid === 0) {
            $this->serveMutualTlsProbe($listener, $reportPath, $nodeId, $challenge);
            exit(0);
        }

        fclose($listener);

        try {
            $transport = new ConfiguredNodeDataPlaneProbeTransport(
                [$nodeId => [
                    'url' => 'https://127.0.0.1:'.$port,
                    'ca_bundle' => $credentials['ca_bundle'],
                ]],
                $credentials['client_certificate'],
                $credentials['client_key'],
            );

            $exchange = $transport->exchange($nodeId, $challenge, 5000);
            $waitedPid = pcntl_waitpid($serverPid, $serverStatus);
            $this->assertSame($serverPid, $waitedPid, 'The local TLS server did not exit after the probe.');
            $this->assertTrue(pcntl_wifexited($serverStatus), 'The local TLS server exited abnormally.');
            $this->assertSame(0, pcntl_wexitstatus($serverStatus), (string) file_get_contents($reportPath));

            $report = json_decode((string) file_get_contents($reportPath), true);
            $this->assertIsArray($report);
            $this->assertSame('TLSv1.3', $report['tls_protocol'] ?? null);
            $this->assertSame('/v1/node/probe', $report['request_path'] ?? null);
            $this->assertSame($nodeId, $report['node_id'] ?? null);
            $this->assertSame($challenge, $report['challenge'] ?? null);
            $clientCertificate = openssl_x509_read((string) file_get_contents($credentials['client_certificate']));
            $this->assertNotFalse($clientCertificate);
            $this->assertSame(openssl_x509_fingerprint($clientCertificate, 'sha256'), $report['client_certificate_sha256'] ?? null);

            $this->assertTrue($exchange->clientIdentityPresented);
            $this->assertTrue($exchange->mutualTlsEstablished);
            $this->assertTrue($exchange->serverIdentityVerified);
            $this->assertSame($nodeId, $exchange->authenticatedNodeId);
            $this->assertTrue($exchange->dataPlaneProbeAccepted);
            $this->assertSame($challenge, $exchange->echoedChallenge);
        } finally {
            if (pcntl_waitpid($serverPid, $serverStatus, WNOHANG) === 0) {
                posix_kill($serverPid, SIGTERM);
                pcntl_waitpid($serverPid, $serverStatus);
            }
        }
    }

    public function test_probe_transport_interoperates_with_go_agent_and_https_canary_over_tls_13_mtls(): void
    {
        if (! function_exists('proc_open') || ! function_exists('stream_socket_server')) {
            $this->markTestSkipped('Process control and stream sockets are required for the PHP-to-Go mTLS integration test.');
        }
        $timeoutCommand = $this->compatibleTimeoutCommand();
        if ($timeoutCommand === null) {
            $this->markTestSkipped('A timeout utility is required for the PHP-to-Go mTLS integration test.');
        }

        $this->seed();
        $credentials = $this->mutualTlsCredentialFiles();
        $nodeId = 'node-test-1';
        $nodeAgentDirectory = base_path('../node-agent');
        $helperSource = $nodeAgentDirectory.'/testdata/php-probe/main.go';
        $helperBinary = $credentials['directory'].'/node-agent-php-probe';
        $build = proc_open(
            [$timeoutCommand, '-k', '1s', '60s', 'go', 'build', '-o', $helperBinary, $helperSource],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $buildPipes,
            $nodeAgentDirectory,
        );
        $this->assertIsResource($build, 'Could not start the Go fixture build.');
        fclose($buildPipes[0]);
        $buildOutput = stream_get_contents($buildPipes[1]);
        $buildError = stream_get_contents($buildPipes[2]);
        fclose($buildPipes[1]);
        fclose($buildPipes[2]);
        $buildExitCode = proc_close($build);
        $this->assertSame(0, $buildExitCode, trim($buildError.' '.$buildOutput));

        $server = proc_open(
            [
                $helperBinary,
                '--certificate', $credentials['server_certificate'],
                '--private-key', $credentials['server_key'],
                '--client-ca', $credentials['ca_bundle'],
                '--node-id', $nodeId,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes,
            $nodeAgentDirectory,
        );
        $this->assertIsResource($server, 'Could not start the Go Node Agent fixture.');
        fclose($serverPipes[0]);
        stream_set_timeout($serverPipes[1], 10);
        $endpoint = trim((string) fgets($serverPipes[1]));

        try {
            $this->assertMatchesRegularExpression('/^https:\/\/127\.0\.0\.1:\d+$/', $endpoint, 'The Go fixture did not announce a loopback-only endpoint.');
            $transport = new ConfiguredNodeDataPlaneProbeTransport(
                [$nodeId => ['url' => $endpoint, 'ca_bundle' => $credentials['ca_bundle']]],
                $credentials['client_certificate'],
                $credentials['client_key'],
            );

            config(['node_health_probe.enabled' => true]);
            $this->app->instance(NodeHealthProbeDriver::class, new MtlsNodeHealthProbeDriver($transport, 5000));
            $this->artisan('nodes:probe-health', ['--node' => [$nodeId]])
                ->expectsOutputToContain('已保存 1 条探测成功和 0 条探测失败的内部状态；这不构成节点连接或会员隧道验收')
                ->assertExitCode(0);
            $status = proc_get_status($server);
            $shutdownDeadline = microtime(true) + 1.0;
            while ($status['running'] && microtime(true) < $shutdownDeadline) {
                usleep(10_000);
                $status = proc_get_status($server);
            }
            $this->assertFalse($status['running'], 'The Go fixture did not shut down after serving one probe.');

            $stateStore = new DatabaseNodeHealthStateStore;
            $persistedState = $stateStore->get($nodeId);
            $this->assertSame('healthy', $persistedState?->status);
            $this->assertSame(0, $persistedState?->failureStreak);
            $this->assertNotNull($persistedState?->lastProbeAt);
            $routeNode = [
                'id' => $nodeId,
                'status' => 'available',
                'health_status' => 'verified_test',
                'last_health_check_at' => $persistedState->lastProbeAt->format(DATE_ATOM),
                'max_sessions' => 10,
                'active_sessions' => 1,
                'weight' => 100,
                'latency_ms' => 25,
                'packet_loss_pct' => 0.0,
                'cpu_pct' => 10.0,
            ];
            $routingPolicy = new NodeRoutingPolicy;
            $candidates = $routingPolicy->rankCandidates(
                [$routeNode],
                'installation-test',
                $persistedState->lastProbeAt,
                ['verified_test'],
                30,
                0.7,
                [$nodeId => $persistedState],
            );
            $this->assertSame([$nodeId], array_map(static fn ($candidate): string => $candidate->nodeId, $candidates));
            $this->assertSame([], $routingPolicy->rankCandidates(
                [$routeNode],
                'installation-test',
                $persistedState->lastProbeAt->modify('+31 seconds'),
                ['verified_test'],
                30,
                0.7,
                [$nodeId => $persistedState],
            ));
            $unhealthyState = new NodeHealthState(
                'unhealthy',
                3,
                0,
                $persistedState->lastProbeAt->modify('+1 second'),
            );
            $stateStore->put($nodeId, $unhealthyState);
            $this->assertSame([], $routingPolicy->rankCandidates(
                [$routeNode],
                'installation-test',
                $unhealthyState->lastProbeAt,
                ['verified_test'],
                30,
                0.7,
                [$nodeId => $stateStore->get($nodeId)],
            ));
        } finally {
            $status = proc_get_status($server);
            $shutdownDeadline = microtime(true) + 1.0;
            while ($status['running'] && microtime(true) < $shutdownDeadline) {
                usleep(10_000);
                $status = proc_get_status($server);
            }
            if ($status['running']) {
                proc_terminate($server, 9);
            }
            fclose($serverPipes[1]);
            fclose($serverPipes[2]);
            proc_close($server);
        }
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

    private function compatibleTimeoutCommand(): ?string
    {
        $path = getenv('PATH');
        if (! is_string($path)) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach (['timeout', 'gtimeout'] as $name) {
                $command = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
                if (is_executable($command)) {
                    return $command;
                }
            }
        }

        return null;
    }

    /** @return array{directory: string, client_certificate: string, client_key: string, server_certificate: string, server_key: string, ca_bundle: string} */
    private function mutualTlsCredentialFiles(): array
    {
        $directory = sys_get_temp_dir().'/xlb-probe-mtls-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;
        $configurationPath = $directory.'/openssl.cnf';
        $configuration = <<<'OPENSSL'
[req]
distinguished_name = distinguished_name
prompt = no

[distinguished_name]
CN = xlb local probe test

[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash

[server_cert]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = IP:127.0.0.1
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer

[client_cert]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = clientAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
OPENSSL;
        $this->assertNotFalse(file_put_contents($configurationPath, $configuration));
        chmod($configurationPath, 0600);

        $authorityKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($authorityKey);
        $authorityRequest = openssl_csr_new(['commonName' => 'xlb-test-ca'], $authorityKey, [
            'config' => $configurationPath,
            'digest_alg' => 'sha256',
        ]);
        $this->assertNotFalse($authorityRequest);
        $authorityCertificate = openssl_csr_sign($authorityRequest, null, $authorityKey, 1, [
            'config' => $configurationPath,
            'x509_extensions' => 'v3_ca',
            'digest_alg' => 'sha256',
        ]);
        $this->assertNotFalse($authorityCertificate);
        $this->writeCertificate($directory.'/ca.pem', $authorityCertificate);

        $server = $this->signedCertificate($configurationPath, $authorityCertificate, $authorityKey, '127.0.0.1', 'server_cert');
        $client = $this->signedCertificate($configurationPath, $authorityCertificate, $authorityKey, 'xlb-probe-client', 'client_cert');
        $this->writeCertificate($directory.'/server.pem', $server['certificate']);
        $this->writePrivateKey($directory.'/server.key', $server['key']);
        $this->writeCertificate($directory.'/client.pem', $client['certificate']);
        $this->writePrivateKey($directory.'/client.key', $client['key']);

        return [
            'directory' => $directory,
            'client_certificate' => $directory.'/client.pem',
            'client_key' => $directory.'/client.key',
            'server_certificate' => $directory.'/server.pem',
            'server_key' => $directory.'/server.key',
            'ca_bundle' => $directory.'/ca.pem',
        ];
    }

    /** @return array{certificate: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey} */
    private function signedCertificate(string $configurationPath, \OpenSSLCertificate $authorityCertificate, \OpenSSLAsymmetricKey $authorityKey, string $commonName, string $extensions): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => $commonName], $key, [
            'config' => $configurationPath,
            'digest_alg' => 'sha256',
        ]);
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign($request, $authorityCertificate, $authorityKey, 1, [
            'config' => $configurationPath,
            'x509_extensions' => $extensions,
            'digest_alg' => 'sha256',
        ]);
        $this->assertNotFalse($certificate);

        return ['certificate' => $certificate, 'key' => $key];
    }

    private function writeCertificate(string $path, \OpenSSLCertificate $certificate): void
    {
        $this->assertTrue(openssl_x509_export($certificate, $pem));
        $this->assertNotFalse(file_put_contents($path, $pem));
        chmod($path, 0600);
    }

    private function writePrivateKey(string $path, \OpenSSLAsymmetricKey $key): void
    {
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->assertNotFalse(file_put_contents($path, $pem));
        chmod($path, 0600);
    }

    /** @param resource $listener */
    private function serveMutualTlsProbe(mixed $listener, string $reportPath, string $nodeId, string $challenge): void
    {
        $connection = @stream_socket_accept($listener, 8);
        if (! is_resource($connection)) {
            file_put_contents($reportPath, json_encode(['error' => 'accept timed out']));
            exit(1);
        }

        stream_set_timeout($connection, 5);
        $secured = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
        if ($secured !== true) {
            file_put_contents($reportPath, json_encode(['error' => 'TLS 1.3 mutual TLS handshake failed']));
            fclose($connection);
            exit(1);
        }

        $request = '';
        while (! str_contains($request, "\r\n\r\n")) {
            $chunk = fread($connection, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }
        [$headers, $body] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
        preg_match('/^POST\s+(\S+)/', $headers, $requestLine);
        preg_match('/^Content-Length:\s*(\d+)/im', $headers, $contentLength);
        $expectedLength = (int) ($contentLength[1] ?? 0);
        while (strlen($body) < $expectedLength) {
            $chunk = fread($connection, min(8192, $expectedLength - strlen($body)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        $payload = json_decode($body, true);
        $context = stream_context_get_params($connection);
        $peerCertificate = $context['options']['ssl']['peer_certificate'] ?? null;
        $peerFingerprint = $peerCertificate instanceof \OpenSSLCertificate
            ? openssl_x509_fingerprint($peerCertificate, 'sha256')
            : null;
        $metadata = stream_get_meta_data($connection);
        $report = [
            'tls_protocol' => $metadata['crypto']['protocol'] ?? null,
            'request_path' => $requestLine[1] ?? null,
            'node_id' => $nodeId,
            'challenge' => $payload['challenge'] ?? null,
            'client_certificate_sha256' => $peerFingerprint,
        ];
        file_put_contents($reportPath, json_encode($report));

        $responseBody = json_encode(['data' => ['node_id' => $nodeId, 'challenge' => $challenge]]);
        $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen((string) $responseBody)."\r\nConnection: close\r\n\r\n".$responseBody;
        fwrite($connection, $response);
        fclose($connection);
    }
}
