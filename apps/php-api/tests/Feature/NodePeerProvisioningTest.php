<?php

namespace Tests\Feature;

use App\Services\NodePeerProvisioningDriver;
use App\Services\NodePeerProvisioningProcessor;
use App\Services\NodePeerProvisioningService;
use App\Services\NodePeerRevocationRequestService;
use App\Services\SandboxNodePeerProvisioningDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class NodePeerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['node_access.provisioning_enabled' => true]);
    }

    public function test_duplicate_provision_requests_keep_the_operation_and_original_request_correlation_ids(): void
    {
        $this->prepareProductionRouteAndDevice();
        $this->mock(NodePeerProvisioningDriver::class);
        $service = app(NodePeerProvisioningService::class);
        $requestCorrelationId = '11111111-1111-4111-8111-111111111111';

        $first = $service->ensureForProvenDevice(
            'user-member', 'device-provision-1', str_repeat('d', 64), $requestCorrelationId,
        );
        $second = $service->ensureForProvenDevice(
            'user-member', 'device-provision-1', str_repeat('d', 64), '22222222-2222-4222-8222-222222222222',
        );

        $this->assertSame('provisioning', $first['status']);
        $this->assertSame('provisioning', $second['status']);
        $this->assertDatabaseCount('node_access_peers', 1);
        $this->assertDatabaseCount('node_access_provisioning_outbox', 1);
        $peer = DB::table('node_access_peers')->first();
        $job = DB::table('node_access_provisioning_outbox')->first();
        $this->assertSame(1, (int) $peer->device_binding_generation);
        $this->assertSame(str_repeat('d', 64), $peer->device_key_fingerprint);
        $this->assertSame($peer->provision_request_id, $job->id);
        $this->assertSame($requestCorrelationId, $job->request_correlation_id);
        $this->assertSame($job->id, $first['node_operation_id']);
        $this->assertSame($job->id, $second['node_operation_id']);
        $this->assertSame(13, (int) DB::table('nodes')->where('id', 'node-test-1')->value('active_sessions'));
    }

    public function test_provisioning_reserves_the_last_node_slot_without_overbooking(): void
    {
        $this->prepareProductionRouteAndDevice();
        DB::table('nodes')->where('id', 'node-test-1')->update(['active_sessions' => 0, 'max_sessions' => 1]);
        DB::table('member_devices')->insert([
            'id' => 'device-provision-2', 'user_id' => 'user-member',
            'installation_id' => 'd6a8c136-1be4-4e40-a3ad-1a47e94c7a14', 'name' => 'Second device',
            'platform' => 'windows', 'status' => 'registered', 'revoked_at' => null,
            'created_at' => now()->utc()->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String(),
            'public_key' => 'second-device-public-key', 'public_key_fingerprint' => str_repeat('f', 64),
            'bound_at' => now()->utc()->toIso8601String(), 'remove_requested_at' => null,
        ]);
        $this->mock(NodePeerProvisioningDriver::class);

        $service = app(NodePeerProvisioningService::class);
        $first = $service->ensureForProvenDevice('user-member', 'device-provision-1', str_repeat('d', 64));
        $second = $service->ensureForProvenDevice('user-member', 'device-provision-2', str_repeat('f', 64));

        $this->assertSame('provisioning', $first['status']);
        $this->assertSame('unavailable', $second['status']);
        $this->assertSame(1, (int) DB::table('nodes')->where('id', 'node-test-1')->value('active_sessions'));
        $this->assertDatabaseCount('node_access_peers', 1);
        $this->assertDatabaseCount('node_access_provisioning_outbox', 1);
    }

    public function test_sandbox_driver_is_repeatable_and_never_returns_a_connectable_profile(): void
    {
        $peer = (object) ['id' => 'peer-sandbox-1', 'device_name' => 'Sandbox device', 'device_key_fingerprint' => 'fingerprint'];
        $node = (object) ['id' => 'node-test-1', 'is_test' => true];
        $driver = new SandboxNodePeerProvisioningDriver;

        $first = $driver->provision($node, $peer, 'stable-request-id', 1, 'sandbox-secret');
        $retry = $driver->provision($node, $peer, 'stable-request-id', 1, 'sandbox-secret');

        $this->assertSame($first, $retry);
        $this->assertSame('confirmed_test', $first['confirmation_status']);
        $this->assertFalse(json_decode($first['profile'], true, 512, JSON_THROW_ON_ERROR)['connectable']);
        $this->assertSame('stable-request-id', $first['request_id']);
    }

    public function test_worker_logs_request_and_operation_ids_without_logging_provisioning_credentials(): void
    {
        $this->prepareProductionRouteAndDevice();
        $requestCorrelationId = '33333333-3333-4333-8333-333333333333';
        $peerId = $this->insertProvisioningPeerAndJob($requestCorrelationId);
        $job = DB::table('node_access_provisioning_outbox')->where('peer_id', $peerId)->first();
        $this->mock(NodePeerProvisioningDriver::class)
            ->shouldReceive('provision')
            ->once()
            ->andThrow(new \RuntimeException('private endpoint and credential'));

        Log::shouldReceive('shareContext')
            ->once()
            ->with(Mockery::on(static fn (array $context): bool => ($context['request_id'] ?? null) === $requestCorrelationId
                && ($context['node_operation_id'] ?? null) === $job->id
                && ($context['node_peer_id'] ?? null) === $peerId));
        Log::shouldReceive('info')
            ->once()
            ->with('Processing node peer provisioning outbox item.');
        Log::shouldReceive('warning')
            ->once()
            ->with('Node peer provisioning attempt failed.', [
                'exception_class' => \RuntimeException::class,
                'deepest_exception_class' => \RuntimeException::class,
            ]);
        Log::shouldReceive('withoutContext')
            ->once()
            ->with(['node_operation_id', 'node_peer_id', 'request_id']);

        $this->assertSame(1, app(NodePeerProvisioningProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_provisioning_outbox', [
            'id' => $job->id,
            'status' => 'retry',
            'request_correlation_id' => $requestCorrelationId,
            'last_error_code' => 'node_agent_error',
        ]);
    }

    public function test_node_agent_connection_failures_have_a_distinct_retry_code(): void
    {
        $this->prepareProductionRouteAndDevice();
        $peerId = $this->insertProvisioningPeerAndJob();
        $job = DB::table('node_access_provisioning_outbox')->where('peer_id', $peerId)->first();
        $this->mock(NodePeerProvisioningDriver::class)
            ->shouldReceive('provision')
            ->once()
            ->andThrow(new ConnectionException('sensitive wrapper detail', 0, new \RuntimeException('private endpoint')));

        Log::shouldReceive('shareContext')
            ->once()
            ->with(Mockery::on(static fn (array $context): bool => ($context['node_operation_id'] ?? null) === $job->id
                && ($context['node_peer_id'] ?? null) === $peerId));
        Log::shouldReceive('info')
            ->once()
            ->with('Processing node peer provisioning outbox item.');
        Log::shouldReceive('warning')
            ->once()
            ->with('Node peer provisioning attempt failed.', [
                'exception_class' => ConnectionException::class,
                'deepest_exception_class' => \RuntimeException::class,
            ]);
        Log::shouldReceive('withoutContext')
            ->once()
            ->with(['node_operation_id', 'node_peer_id']);

        $this->assertSame(1, app(NodePeerProvisioningProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_provisioning_outbox', [
            'id' => $job->id,
            'status' => 'retry',
            'last_error_code' => 'node_agent_connection_error',
        ]);
    }

    public function test_http_provisioning_interoperates_with_go_node_agent_over_tls_13_mtls(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Process control is required for the PHP-to-Go mTLS integration test.');
        }
        $timeoutCommand = $this->compatibleTimeoutCommand();
        if ($timeoutCommand === null) {
            $this->markTestSkipped('A timeout utility is required for the PHP-to-Go mTLS integration test.');
        }

        $directory = sys_get_temp_dir().'/xlb-peer-provisioning-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $nodeAgentDirectory = base_path('../node-agent');
        $helperSource = $nodeAgentDirectory.'/testdata/php-provisioning/main.go';
        $helperBinary = $directory.'/node-agent-php-provisioning';
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
        $this->assertSame(0, proc_close($build), trim($buildError.' '.$buildOutput));

        $nodeId = 'node-test-1';
        $server = proc_open(
            [
                $helperBinary, '--directory', $directory, '--node-id', $nodeId,
                '--capacity', '4', '--bridge-listen-address', '127.0.0.1:17890',
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes,
            $nodeAgentDirectory,
        );
        $this->assertIsResource($server, 'Could not start the Go Node Agent fixture.');
        fclose($serverPipes[0]);
        stream_set_timeout($serverPipes[1], 10);
        $startup = json_decode((string) fgets($serverPipes[1]), true);
        $originalNoProxy = getenv('NO_PROXY');
        $serverNoProxyExists = array_key_exists('NO_PROXY', $_SERVER);
        $originalServerNoProxy = $_SERVER['NO_PROXY'] ?? null;

        try {
            $noProxyHosts = array_values(array_filter(array_map(
                'trim',
                explode(',', is_string($originalServerNoProxy)
                    ? $originalServerNoProxy
                    : (is_string($originalNoProxy) ? $originalNoProxy : '')),
            )));
            $noProxyHosts = array_values(array_unique([...$noProxyHosts, '127.0.0.1', 'localhost']));
            $noProxy = implode(',', $noProxyHosts);
            $_SERVER['NO_PROXY'] = $noProxy;
            putenv('NO_PROXY='.$noProxy);

            $this->assertIsArray($startup);
            $this->assertMatchesRegularExpression('/^https:\/\/127\.0\.0\.1:\d+$/', $startup['endpoint'] ?? '');
            $this->assertFileExists($startup['ca_certificate'] ?? '');
            $this->assertFileExists($startup['client_certificate'] ?? '');
            $this->assertFileExists($startup['client_private_key'] ?? '');

            $this->prepareProductionRouteAndDevice();
            $deviceKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $this->assertNotFalse($deviceKey);
            $deviceKeyDetails = openssl_pkey_get_details($deviceKey);
            $this->assertIsArray($deviceKeyDetails);
            $publicKeyBase64 = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $deviceKeyDetails['key']);
            $this->assertIsString($publicKeyBase64);
            $publicKeyDer = base64_decode($publicKeyBase64, true);
            $this->assertIsString($publicKeyDer);
            $fingerprint = hash('sha256', $publicKeyDer);
            DB::table('member_devices')->where('id', 'device-provision-1')->update([
                'public_key' => rtrim(strtr(base64_encode($publicKeyDer), '+/', '-_'), '='),
                'public_key_fingerprint' => $fingerprint,
            ]);
            $bridgeTrustBundle = file_get_contents($startup['ca_certificate']);
            $this->assertIsString($bridgeTrustBundle);
            DB::table('nodes')->where('id', $nodeId)->update([
                'agent_endpoint' => $startup['endpoint'],
                'member_bridge_endpoint' => $startup['endpoint'],
                'member_bridge_ca_ciphertext' => Crypt::encryptString($bridgeTrustBundle),
            ]);
            config([
                'node_access.driver' => 'agent',
                'node_access.provisioning_enabled' => true,
                'node_access.agent.timeout_seconds' => 15,
                'node_access.agent.client_certificate' => $startup['client_certificate'],
                'node_access.agent.client_key' => $startup['client_private_key'],
                'node_access.agent.ca_bundle' => $startup['ca_certificate'],
            ]);
            $this->app->forgetInstance(NodePeerProvisioningDriver::class);

            $service = app(NodePeerProvisioningService::class);
            $pending = $service->ensureForProvenDevice('user-member', 'device-provision-1', $fingerprint);
            $this->assertSame('provisioning', $pending['status']);
            $this->assertSame(1, app(NodePeerProvisioningProcessor::class)->processDue());

            $peer = DB::table('node_access_peers')->where('device_id', 'device-provision-1')->first();
            $this->assertNotNull($peer);
            $outboxAfterProcessing = DB::table('node_access_provisioning_outbox')
                ->where('peer_id', $peer->id)->first();
            $this->assertNotNull($outboxAfterProcessing);
            $this->assertSame('confirmed', $outboxAfterProcessing->status, json_encode([
                'peer_id' => $peer->id,
                'peer_status' => $peer->status,
                'outbox_status' => $outboxAfterProcessing->status,
                'last_error_code' => $outboxAfterProcessing->last_error_code,
                'attempts' => $outboxAfterProcessing->attempts,
            ]));
            $this->assertSame(1, (int) $outboxAfterProcessing->attempts);
            $this->assertSame('active', $peer->status, json_encode([
                'peer_id' => $peer->id,
                'outbox_status' => $outboxAfterProcessing->status,
                'last_error_code' => $outboxAfterProcessing->last_error_code,
                'attempts' => $outboxAfterProcessing->attempts,
            ]));
            $this->assertSame('confirmed', $peer->provision_confirmation_status);
            $this->assertSame('mihomo-yaml', $peer->profile_format);
            $this->assertSame('xlb-mihomo-bridge-v1', $peer->profile_renderer_id);
            $this->assertSame($startup['endpoint'], $peer->bridge_endpoint, json_encode([
                'startup' => $startup['endpoint'] ?? null,
                'bridge_endpoint' => $peer->bridge_endpoint ?? null,
            ]));
            $this->assertStringNotContainsString('BEGIN CERTIFICATE', $peer->bridge_server_ca_ciphertext);
            $trustedDataPlaneCa = Crypt::decryptString($peer->bridge_server_ca_ciphertext);
            $profile = Crypt::decryptString($peer->profile_ciphertext);
            $this->assertSame(hash('sha256', $profile), $peer->profile_sha256);
            $this->assertStringContainsString('127.0.0.1', $profile);
            $this->assertStringNotContainsString('private_key', strtolower($profile));
            $this->assertStringNotContainsString('peer_credential', strtolower($profile));

            $report = json_decode((string) file_get_contents($startup['client_report']), true);
            $this->assertIsArray($report);
            $this->assertTrue($report['tls_present'] ?? false, json_encode($report));
            $this->assertSame('TLS1.3', $report['tls_version'] ?? null, json_encode($report));
            $this->assertGreaterThanOrEqual(1, $report['peer_certificate_count'] ?? 0, json_encode($report));
            $this->assertGreaterThanOrEqual(1, $report['verified_chain_count'] ?? 0, json_encode($report));
            $this->assertTrue($report['verified_chain'] ?? false);
            $this->assertSame('/v1/member-peers/ensure', $report['request_path'] ?? null);
            $clientCertificate = openssl_x509_read((string) file_get_contents($startup['client_certificate']));
            $this->assertNotFalse($clientCertificate);
            $this->assertSame(openssl_x509_fingerprint($clientCertificate, 'sha256'), $report['client_certificate_sha256'] ?? null);

            $ready = $service->ensureForProvenDevice('user-member', 'device-provision-1', $fingerprint);
            $this->assertSame('ready', $ready['status']);
            $this->assertSame($peer->provision_request_id, $ready['node_operation_id']);
            $this->assertSame($peer->id, $ready['peer_id']);
            $this->assertSame($nodeId, $ready['node_id']);
            $this->assertSame($profile, $ready['profile']);
            $this->assertSame($startup['endpoint'], $ready['bridge_endpoint']);
            $this->assertSame($trustedDataPlaneCa, $ready['bridge_server_ca_pem']);
            $this->assertSame(hash('sha256', $profile), $ready['profile_sha256']);
            $this->assertSame('xlb-mihomo-bridge-v1', $ready['profile_renderer_id']);
            $this->assertSame('1.0.0', $ready['profile_renderer_version']);
            $this->assertSame('xlb-proof-v1', $ready['bridge_protocol']);
            $this->assertTrue($ready['connectable']);
            $this->assertSame(Crypt::decryptString($peer->credential_ciphertext), $ready['peer_credential']);

            DB::table('node_access_peers')->where('id', $peer->id)->update([
                'bridge_endpoint' => 'https://127.0.0.1:1',
            ]);
            $this->assertSame('unavailable', $service->ensureForProvenDevice(
                'user-member', 'device-provision-1', $fingerprint,
            )['status']);
        } finally {
            $status = proc_get_status($server);
            $shutdownDeadline = microtime(true) + 2.0;
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
            if ($originalNoProxy === false) {
                putenv('NO_PROXY');
            } else {
                putenv('NO_PROXY='.$originalNoProxy);
            }
            if ($serverNoProxyExists) {
                $_SERVER['NO_PROXY'] = $originalServerNoProxy;
            } else {
                unset($_SERVER['NO_PROXY']);
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    public function test_timeout_after_node_execution_retries_idempotently_and_encrypts_peer_profile(): void
    {
        $this->prepareProductionRouteAndDevice();
        $peerId = $this->insertProvisioningPeerAndJob();
        $job = DB::table('node_access_provisioning_outbox')->where('peer_id', $peerId)->first();
        $profile = '{"scope":"isolated-test-only","connectable":false}';
        $acknowledgement = [
            'node_id' => 'node-test-1', 'peer_id' => $peerId, 'request_id' => $job->id,
            'generation' => (int) $job->generation, 'acknowledgement_id' => 'agent-ack-1',
            'profile' => $profile, 'confirmation_status' => 'confirmed_test',
        ];
        $requestIds = [];
        $nodeExecuted = false;
        $driver = $this->mock(NodePeerProvisioningDriver::class);
        $driver->shouldReceive('provision')->twice()->andReturnUsing(
            function ($node, $peer, string $requestId, int $generation, string $credential) use (
                &$requestIds,
                &$nodeExecuted,
                $acknowledgement,
            ): array {
                $requestIds[] = $requestId;
                $this->assertSame('node-test-1', $node->id);
                $this->assertSame('peer-provision-1', $peer->id);
                $this->assertSame(1, $generation);
                $this->assertSame('stored-device-secret', $credential);
                if (! $nodeExecuted) {
                    $nodeExecuted = true;
                    throw new \RuntimeException('response timed out after node execution');
                }

                return $acknowledgement;
            }
        );
        $processor = app(NodePeerProvisioningProcessor::class);

        $this->assertSame(1, $processor->processDue());
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'provisioning']);
        $this->assertDatabaseHas('node_access_provisioning_outbox', ['id' => $job->id, 'status' => 'retry']);
        $this->travel(11)->seconds();
        $this->assertSame(1, $processor->processDue());

        $peer = DB::table('node_access_peers')->where('id', $peerId)->first();
        $this->assertSame([$job->id, $job->id], $requestIds);
        $this->assertSame('active', $peer->status, json_encode((array) DB::table('node_access_provisioning_outbox')->where('id', $job->id)->first()));
        $this->assertNotSame($profile, $peer->profile_ciphertext);
        $this->assertSame($profile, Crypt::decryptString($peer->profile_ciphertext));
        $this->assertNotSame('stored-device-secret', $peer->credential_ciphertext);
        $this->assertSame('stored-device-secret', Crypt::decryptString($peer->credential_ciphertext));
        $this->assertDatabaseHas('node_access_provisioning_outbox', [
            'id' => $job->id, 'status' => 'confirmed_test', 'acknowledgement_id' => 'agent-ack-1',
        ]);
    }

    public function test_peer_is_not_activated_when_its_entitlement_changes_during_node_provisioning(): void
    {
        $this->prepareProductionRouteAndDevice();
        $peerId = $this->insertProvisioningPeerAndJob();
        $job = DB::table('node_access_provisioning_outbox')->where('peer_id', $peerId)->first();
        $peer = DB::table('node_access_peers')->where('id', $peerId)->first();
        $previousEntitlementId = $peer->entitlement_id;
        $newEntitlementId = 'entitlement-member-renewal';
        $this->mock(NodePeerProvisioningDriver::class)->shouldReceive('provision')->once()
            ->andReturnUsing(function ($node, $peer, string $requestId, int $generation) use (
                $previousEntitlementId,
                $newEntitlementId,
            ): array {
                DB::table('entitlements')->where('id', $previousEntitlementId)->update(['status' => 'revoked_test']);
                DB::table('entitlements')->insert([
                    'id' => $newEntitlementId, 'user_id' => 'user-member', 'plan_id' => 'annual',
                    'status' => 'active', 'starts_at' => now()->subMinute()->utc()->toIso8601String(),
                    'ends_at' => now()->addYear()->utc()->toIso8601String(), 'data_used_gb' => 0,
                ]);

                return [
                    'node_id' => $node->id, 'peer_id' => $peer->id, 'request_id' => $requestId,
                    'generation' => $generation, 'acknowledgement_id' => 'stale-entitlement-ack',
                    'profile' => '{"scope":"isolated-test-only","connectable":false}', 'confirmation_status' => 'confirmed_test',
                ];
            });

        $this->assertSame(1, app(NodePeerProvisioningProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peerId, 'entitlement_id' => $previousEntitlementId, 'status' => 'revocation_pending',
        ]);
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'peer_id' => $peerId, 'status' => 'pending', 'reason' => 'access_lost_during_provision',
        ]);
        $this->assertDatabaseHas('entitlements', ['id' => $newEntitlementId, 'status' => 'active']);
        $this->assertDatabaseHas('node_access_provisioning_outbox', [
            'id' => $job->id, 'status' => 'superseded', 'acknowledgement_id' => 'stale-entitlement-ack',
        ]);
    }

    public function test_duplicate_revocation_requests_reuse_the_same_request_id_and_generation(): void
    {
        $this->prepareProductionRouteAndDevice();
        $peerId = $this->insertProvisioningPeerAndJob();
        $peer = DB::table('node_access_peers')->where('id', $peerId)->first();
        $service = app(NodePeerRevocationRequestService::class);

        $first = $service->request($peer, 'device_removed');
        $retry = $service->request($peer, 'refund');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(2, (int) $retry->generation);
        $this->assertSame('device_removed', $retry->reason);
        $this->assertDatabaseCount('node_access_revocation_outbox', 1);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peerId,
            'status' => 'revocation_pending',
            'generation' => 2,
        ]);
    }

    public function test_wrong_node_provision_ack_keeps_the_peer_pending_for_retry(): void
    {
        $this->prepareProductionRouteAndDevice();
        $peerId = $this->insertProvisioningPeerAndJob();
        $job = DB::table('node_access_provisioning_outbox')->where('peer_id', $peerId)->first();
        $driver = $this->mock(NodePeerProvisioningDriver::class);
        $driver->shouldReceive('provision')->once()->andReturn([
            'node_id' => 'node-other', 'peer_id' => $peerId, 'request_id' => $job->id,
            'generation' => (int) $job->generation, 'acknowledgement_id' => 'wrong-node-ack',
            'profile' => '{"connectable":false}', 'confirmation_status' => 'confirmed',
        ]);

        $this->assertSame(1, app(NodePeerProvisioningProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'provisioning']);
        $this->assertDatabaseHas('node_access_provisioning_outbox', [
            'id' => $job->id, 'status' => 'retry', 'last_error_code' => 'node_agent_error',
        ]);
    }

    private function prepareProductionRouteAndDevice(): void
    {
        $now = now()->utc()->toIso8601String();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'is_test' => false,
            'health_status' => 'healthy',
            'last_health_check_at' => $now,
            'agent_endpoint' => 'https://node-agent.example.invalid',
        ]);
        DB::table('subscription_route_versions')->insert([
            'id' => 'route-production-v1', 'scope' => 'production', 'version' => 1,
            'status' => 'published', 'canary_percent' => 100, 'actor_user_id' => 'user-admin',
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('subscription_route_nodes')->insert([
            'id' => 'route-production-v1-node-1', 'route_version_id' => 'route-production-v1',
            'node_id' => 'node-test-1', 'weight' => 100, 'created_at' => $now,
        ]);
        DB::table('member_devices')->insert([
            'id' => 'device-provision-1', 'user_id' => 'user-member',
            'installation_id' => '3680e06f-5df2-44d9-90ea-8cfa05a81647', 'name' => 'Test Android',
            'platform' => 'android', 'status' => 'registered', 'revoked_at' => null,
            'created_at' => $now, 'updated_at' => $now, 'public_key' => 'device-public-key',
            'public_key_fingerprint' => str_repeat('d', 64), 'bound_at' => $now,
            'remove_requested_at' => null,
        ]);
    }

    private function insertProvisioningPeerAndJob(?string $requestCorrelationId = null): string
    {
        $now = now()->utc()->toIso8601String();
        DB::table('node_access_peers')->insert([
            'id' => 'peer-provision-1', 'user_id' => 'user-member', 'node_id' => 'node-test-1',
            'device_id' => 'device-provision-1', 'entitlement_id' => DB::table('entitlements')->where('user_id', 'user-member')->value('id'),
            'device_name' => 'Test Android',
            'public_key' => 'peer-public-provision-1', 'device_key_fingerprint' => str_repeat('e', 64),
            'generation' => 1, 'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => Crypt::encryptString('stored-device-secret'), 'profile_ciphertext' => null,
            'provision_request_id' => 'request-provision-1', 'provision_acknowledgement_id' => null,
            'provision_confirmation_status' => null, 'status' => 'provisioning',
            'upload_bytes' => 0, 'download_bytes' => 0, 'last_observed_at' => null,
            'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('node_access_provisioning_outbox')->insert([
            'id' => 'request-provision-1', 'peer_id' => 'peer-provision-1', 'status' => 'pending',
            'generation' => 1, 'attempts' => 0, 'next_attempt_at' => $now,
            'request_correlation_id' => $requestCorrelationId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return 'peer-provision-1';
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
}
