<?php

namespace Tests\Feature;

use App\Services\TrafficQuotaExceeded;
use App\Services\TrafficQuotaReservationConflict;
use App\Services\TrafficQuotaReservationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NodePeerTrafficQuotaTest extends TestCase
{
    private string $databasePath;

    private string $defaultDatabaseConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'xlb-quota-');
        $this->assertNotFalse($path);
        $this->databasePath = $path;
        $this->defaultDatabaseConnection = (string) config('database.default');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.busy_timeout' => 15000,
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);
        DB::setDefaultConnection('sqlite');
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);
        $this->seed();
        $this->createPeer(1024);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        foreach (glob($this->databasePath.'-*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        config(['database.default' => $this->defaultDatabaseConnection]);
        DB::setDefaultConnection($this->defaultDatabaseConnection);

        parent::tearDown();
    }

    public function test_reservation_is_idempotent_and_refuses_capacity_overcommit(): void
    {
        $service = app(TrafficQuotaReservationService::class);
        $first = $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-1', 700);
        $retry = $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-1', 700);

        $this->assertSame($first, $retry);
        $this->assertSame('entitlement-member-annual', $first['entitlement_id']);
        try {
            $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-1', 699);
            $this->fail('Reusing a request ID with a different grant must be rejected.');
        } catch (TrafficQuotaReservationConflict) {
            $this->assertSame(700, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        }
        $this->expectException(TrafficQuotaExceeded::class);
        $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-2', 325);
    }

    public function test_reservation_retry_returns_original_result_even_if_peer_changed_after_creation(): void
    {
        $service = app(TrafficQuotaReservationService::class);
        $first = $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-peer-change', 128);
        DB::table('node_access_peers')->where('id', 'peer-quota-1')->update(['status' => 'revoked']);

        $retry = $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-peer-change', 128);

        $this->assertSame($first, $retry);
        $this->assertSame(128, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        $this->assertSame(1, DB::table('node_traffic_reservations')->where('request_id', 'quota-request-peer-change')->count());
    }

    public function test_concurrent_retries_with_the_same_request_id_share_one_reservation(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the isolated multi-process reservation proof.');
        }

        $workerCount = 8;
        $resultPaths = [];
        $children = [];
        for ($index = 0; $index < $workerCount; $index++) {
            $resultPath = tempnam(sys_get_temp_dir(), 'xlb-quota-idempotency-');
            $this->assertNotFalse($resultPath);
            $resultPaths[] = $resultPath;
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('sqlite');
                try {
                    $reservation = app(TrafficQuotaReservationService::class)->reserve(
                        'entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1,
                        'quota-request-concurrent-retry', 128,
                    );
                    file_put_contents($resultPath, $reservation['id']);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($resultPath, 'error:'.$exception::class.':'.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        $exitCodes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        DB::purge('sqlite');
        $results = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultPaths);
        foreach ($resultPaths as $path) {
            unlink($path);
        }

        $this->assertSame(array_fill(0, $workerCount, 0), $exitCodes, implode('; ', $results));
        $this->assertCount(1, array_unique($results));
        $this->assertSame(128, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        $this->assertSame(1, DB::table('node_traffic_reservations')->where('request_id', 'quota-request-concurrent-retry')->count());
    }

    public function test_reservation_request_id_must_be_a_safe_url_segment(): void
    {
        $this->expectException(\RuntimeException::class);
        app(TrafficQuotaReservationService::class)->reserve(
            'entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'request/escape', 1,
        );
    }

    public function test_node_quota_ingress_requires_verified_certificate_and_a_production_node(): void
    {
        $this->postJson('/v1/node-quota/reservations', [])->assertUnauthorized();

        [$certificate, $fingerprint] = $this->createNodeClientCertificate();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'agent_client_cert_sha256' => $fingerprint,
            'status' => 'available', 'health_status' => 'healthy', 'is_test' => true,
        ]);
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $certificate,
        ])->postJson('/v1/node-quota/reservations', [
            'node_id' => 'node-test-1', 'entitlement_id' => 'entitlement-member-annual',
            'peer_id' => 'peer-quota-1', 'generation' => 1, 'request_id' => 'quota-ingress-test', 'grant_bytes' => 1,
        ])->assertUnauthorized();

        DB::table('nodes')->where('id', 'node-test-1')->update(['is_test' => false]);
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $certificate,
        ])->postJson('/v1/node-quota/reservations', [
            'node_id' => 'another-node', 'entitlement_id' => 'entitlement-member-annual',
            'peer_id' => 'peer-quota-1', 'generation' => 1, 'request_id' => 'quota-ingress-wrong-node', 'grant_bytes' => 1,
        ])->assertForbidden();
    }

    public function test_node_quota_ingress_rejects_disabled_nodes_and_rotated_certificates(): void
    {
        [$oldCertificate, $oldFingerprint] = $this->createNodeClientCertificate();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'agent_client_cert_sha256' => $oldFingerprint,
            'status' => 'available', 'health_status' => 'healthy', 'is_test' => false,
        ]);
        $payload = [
            'node_id' => 'node-test-1', 'entitlement_id' => 'entitlement-member-annual',
            'peer_id' => 'peer-quota-1', 'generation' => 1, 'request_id' => 'quota-cert-old', 'grant_bytes' => 1,
        ];
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $oldCertificate,
        ])->postJson('/v1/node-quota/reservations', $payload)->assertOk();

        [$newCertificate, $newFingerprint] = $this->createNodeClientCertificate();
        DB::table('nodes')->where('id', 'node-test-1')->update(['agent_client_cert_sha256' => $newFingerprint]);
        $payload['request_id'] = 'quota-cert-rotated';
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $oldCertificate,
        ])->postJson('/v1/node-quota/reservations', $payload)->assertUnauthorized();
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $newCertificate,
        ])->postJson('/v1/node-quota/reservations', $payload)->assertOk();

        DB::table('nodes')->where('id', 'node-test-1')->update(['status' => 'disabled']);
        $payload['request_id'] = 'quota-cert-disabled';
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $newCertificate,
        ])->postJson('/v1/node-quota/reservations', $payload)->assertUnauthorized();

        DB::table('nodes')->where('id', 'node-test-1')->update(['status' => 'available', 'health_status' => 'degraded']);
        $payload['request_id'] = 'quota-cert-unhealthy';
        $this->withServerVariables([
            'SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $newCertificate,
        ])->postJson('/v1/node-quota/reservations', $payload)->assertUnauthorized();
    }

    public function test_node_quota_ingress_reserves_and_settles_shared_entitlement_bytes(): void
    {
        [$certificate, $fingerprint] = $this->createNodeClientCertificate();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'agent_client_cert_sha256' => $fingerprint,
            'status' => 'available', 'health_status' => 'healthy', 'is_test' => false,
        ]);
        $serverVariables = ['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $certificate];

        $reservationContract = json_decode(
            (string) file_get_contents(base_path('../node-agent/testdata/laravel-quota-reservation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $reservationResponse = $this->withServerVariables($serverVariables)->postJson('/v1/node-quota/reservations', [
            'node_id' => 'node-test-1', 'entitlement_id' => 'entitlement-member-annual',
            'peer_id' => 'peer-quota-1', 'generation' => 1, 'request_id' => 'quota-ingress-1',
            'grant_bytes' => 512, 'lease_seconds' => 30,
        ])->assertOk();
        $reservationContract['data']['id'] = $reservationResponse->json('data.id');
        $reservationContract['data']['lease_expires_at'] = $reservationResponse->json('data.lease_expires_at');
        $reservationResponse->assertExactJson($reservationContract);

        $settlementContract = json_decode(
            (string) file_get_contents(base_path('../node-agent/testdata/laravel-quota-settlement-ack.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $settlementResponse = $this->withServerVariables($serverVariables)->postJson('/v1/node-quota/reservations/quota-ingress-1/settlements', [
            'node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1', 'generation' => 1,
            'request_id' => 'quota-ingress-1', 'acknowledgement_id' => 'quota-ingress-ack-1',
            'upload_bytes' => 100, 'download_bytes' => 150, 'final' => true,
            'confirmation_status' => 'confirmed_test',
        ])->assertOk();
        $settlementResponse->assertExactJson($settlementContract);

        $this->assertSame(250, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('used_bytes'));
        $this->assertSame(0, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
    }

    public function test_go_quota_authority_integrates_with_laravel_over_local_mutual_tls(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the local Go/Laravel integration test.');
        }

        [$certificate, $fingerprint, $privateKey] = $this->createNodeClientCertificate();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'agent_client_cert_sha256' => $fingerprint,
            'status' => 'available', 'health_status' => 'healthy', 'is_test' => false,
        ]);
        $certificatePath = $this->databasePath.'-node.crt';
        $privateKeyPath = $this->databasePath.'-node.key';
        $this->assertNotFalse(file_put_contents($certificatePath, $certificate));
        $this->assertNotFalse(file_put_contents($privateKeyPath, $privateKey));
        chmod($certificatePath, 0600);
        chmod($privateKeyPath, 0600);

        $requestId = (string) Str::uuid();
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
            'XLB_LARAVEL_APP_KEY' => (string) config('app.key'),
            'XLB_LARAVEL_CGI_INTEGRATION' => '1',
            'XLB_LARAVEL_PHP_API_ROOT' => base_path(),
            'XLB_LARAVEL_DB_PATH' => $this->databasePath,
            'XLB_LARAVEL_CLIENT_CERT' => $certificatePath,
            'XLB_LARAVEL_CLIENT_KEY' => $privateKeyPath,
            'XLB_LARAVEL_REQUEST_ID' => $requestId,
        ];
        $pipes = [];
        $process = proc_open(
            ['go', 'test', '-run', '^TestHTTPQuotaAuthorityAgainstLaravelCGI$', '-count=1', '-timeout=45s', '.'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path('../node-agent'),
            $environment,
        );
        if (! is_resource($process)) {
            $this->fail('Could not start the local Go/Laravel mTLS integration test.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $this->assertSame(0, $exitCode, trim((string) $stdout."\n".(string) $stderr));

        $this->assertSame(57, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('used_bytes'));
        $this->assertSame(0, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        $this->assertSame('settled', DB::table('node_traffic_reservations')->where('request_id', $requestId)->value('status'));
        $this->assertSame(2, DB::table('node_traffic_reservations')->where('peer_id', 'peer-quota-1')->where('status', 'settled')->count());
    }

    public function test_final_receipt_releases_only_unused_credit_and_snapshot_does_not_double_bill(): void
    {
        $service = app(TrafficQuotaReservationService::class);
        $reservation = $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-3', 512);
        $service->applyUsageSnapshot('peer-quota-1', 80, 120);
        $this->assertSame(200, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('used_bytes'));
        $this->assertSame(512, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));

        $service->settle([
            'node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1', 'request_id' => 'quota-request-3',
            'generation' => 1, 'acknowledgement_id' => 'quota-ack-1', 'upload_bytes' => 80,
            'download_bytes' => 120, 'final' => true, 'confirmation_status' => 'confirmed_test',
        ]);
        $service->applyUsageSnapshot('peer-quota-1', 80, 120);

        $this->assertSame(200, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('used_bytes'));
        $this->assertSame(0, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        $this->assertSame('settled', DB::table('node_traffic_reservations')->where('id', $reservation['id'])->value('status'));
        $this->assertEqualsWithDelta(200 / (1024 ** 3), (float) DB::table('entitlements')->where('id', 'entitlement-member-annual')->value('data_used_gb'), 1e-12);
    }

    public function test_entitlement_budget_is_shared_across_peers_on_distinct_nodes(): void
    {
        $this->createPeerOnNode('node-quota-2', 'peer-quota-2', 'device-quota-2');
        $service = app(TrafficQuotaReservationService::class);
        $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-node-a', 700);

        $this->expectException(TrafficQuotaExceeded::class);
        $service->reserve('entitlement-member-annual', 'node-quota-2', 'peer-quota-2', 1, 'quota-node-b', 325);
    }

    public function test_invalid_or_non_monotonic_receipts_keep_the_reservation_held(): void
    {
        $service = app(TrafficQuotaReservationService::class);
        $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-4', 512);

        try {
            $service->settle([
                'node_id' => 'node-other', 'peer_id' => 'peer-quota-1', 'request_id' => 'quota-request-4',
                'generation' => 1, 'acknowledgement_id' => 'bad-ack', 'upload_bytes' => 10,
                'download_bytes' => 10, 'final' => true, 'confirmation_status' => 'confirmed_test',
            ]);
            $this->fail('A receipt bound to another node must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame(512, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        }

        try {
            $service->settle([
                'node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1', 'request_id' => 'quota-request-4',
                'generation' => 1, 'acknowledgement_id' => 'over-grant-ack', 'upload_bytes' => 300,
                'download_bytes' => 300, 'final' => true, 'confirmation_status' => 'confirmed_test',
            ]);
            $this->fail('Final traffic cannot exceed the reserved grant.');
        } catch (\RuntimeException) {
            $this->assertSame(512, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        }

        $service->settle([
            'node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1', 'request_id' => 'quota-request-4',
            'generation' => 1, 'acknowledgement_id' => 'quota-ack-2', 'upload_bytes' => 20,
            'download_bytes' => 10, 'final' => false, 'confirmation_status' => 'confirmed_test',
        ]);
        try {
            $service->settle([
                'node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1', 'request_id' => 'quota-request-4',
                'generation' => 1, 'acknowledgement_id' => 'quota-ack-3', 'upload_bytes' => 19,
                'download_bytes' => 10, 'final' => true, 'confirmation_status' => 'confirmed_test',
            ]);
            $this->fail('A receipt that moves counters backwards must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame(482, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        }
    }

    public function test_concurrent_reservations_across_workers_never_overbook_the_shared_entitlement(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the isolated multi-process reservation proof.');
        }

        $service = app(TrafficQuotaReservationService::class);
        $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-prime-budget', 1);
        $service->settle([
            'node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1', 'request_id' => 'quota-prime-budget',
            'generation' => 1, 'acknowledgement_id' => 'quota-prime-final', 'upload_bytes' => 0,
            'download_bytes' => 0, 'final' => true, 'confirmation_status' => 'confirmed_test',
        ]);
        DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->update([
            'quota_bytes' => 3, 'used_bytes' => 0, 'reserved_bytes' => 0,
        ]);

        $peers = [
            ['node_id' => 'node-test-1', 'peer_id' => 'peer-quota-1'],
            ['node_id' => 'node-quota-2', 'peer_id' => 'peer-quota-2'],
            ['node_id' => 'node-quota-3', 'peer_id' => 'peer-quota-3'],
        ];
        $this->createPeerOnNode('node-quota-2', 'peer-quota-2', 'device-quota-2');
        $this->createPeerOnNode('node-quota-3', 'peer-quota-3', 'device-quota-3');

        $workerCount = 12;
        $resultPaths = [];
        $children = [];
        for ($index = 0; $index < $workerCount; $index++) {
            $resultPath = tempnam(sys_get_temp_dir(), 'xlb-quota-result-');
            $this->assertNotFalse($resultPath);
            $resultPaths[] = $resultPath;
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('sqlite');
                try {
                    $peer = $peers[$index % count($peers)];
                    app(TrafficQuotaReservationService::class)->reserve(
                        'entitlement-member-annual', $peer['node_id'], $peer['peer_id'], 1,
                        (string) Str::uuid(), 1,
                    );
                    file_put_contents($resultPath, 'reserved');
                    exit(0);
                } catch (TrafficQuotaExceeded) {
                    file_put_contents($resultPath, 'full');
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($resultPath, 'error:'.$exception::class.':'.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        $exitCodes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        DB::purge('sqlite');
        $results = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultPaths);
        foreach ($resultPaths as $path) {
            unlink($path);
        }

        $this->assertSame(array_fill(0, $workerCount, 0), $exitCodes, implode('; ', $results));
        $this->assertSame(3, count(array_filter($results, static fn (string $result): bool => $result === 'reserved')));
        $this->assertSame(9, count(array_filter($results, static fn (string $result): bool => $result === 'full')));
        $this->assertSame(3, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        $this->assertSame(3, DB::table('node_traffic_reservations')->where('status', 'reserved')->count());
    }

    public function test_expired_lease_without_a_final_receipt_keeps_its_bytes_reserved(): void
    {
        $service = app(TrafficQuotaReservationService::class);
        $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-expired', 1024, 5);
        $this->travel(6)->seconds();

        try {
            $service->reserve('entitlement-member-annual', 'node-test-1', 'peer-quota-1', 1, 'quota-request-after-expiry', 1);
            $this->fail('Lease timeout alone must not release bytes while the remote outcome is unknown.');
        } catch (TrafficQuotaExceeded) {
            $this->assertSame(1024, (int) DB::table('entitlement_traffic_budgets')->where('entitlement_id', 'entitlement-member-annual')->value('reserved_bytes'));
        }
    }

    public function test_direct_snapshot_path_rejects_negative_and_overflowed_counters_before_mutation(): void
    {
        $service = app(TrafficQuotaReservationService::class);
        $peer = DB::table('node_access_peers')->where('id', 'peer-quota-1')->first();
        foreach ([[-1, 0], [PHP_INT_MAX, 1]] as [$upload, $download]) {
            try {
                $service->recordUsageSnapshotWithinTransaction($peer, $upload, $download);
                $this->fail('Invalid node counters must be refused on the direct transaction path.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('supported range', $exception->getMessage());
                $this->assertSame(0, (int) DB::table('node_access_peers')->where('id', 'peer-quota-1')->value('upload_bytes'));
            }
        }
    }

    private function createPeer(int $quotaBytes): void
    {
        $now = now()->utc()->toIso8601String();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'is_test' => false, 'status' => 'available', 'health_status' => 'healthy',
        ]);
        DB::table('plans')->where('id', 'annual')->update(['traffic_gb' => $quotaBytes / (1024 ** 3)]);
        DB::table('entitlements')->where('id', 'entitlement-member-annual')->update(['data_used_gb' => 0]);
        DB::table('member_devices')->insert([
            'id' => 'device-quota-1', 'user_id' => 'user-member', 'installation_id' => (string) Str::uuid(),
            'name' => 'Quota test device', 'platform' => 'android', 'status' => 'registered', 'revoked_at' => null,
            'created_at' => $now, 'updated_at' => $now, 'public_key' => 'quota-device-public-key',
            'public_key_fingerprint' => str_repeat('q', 64), 'bound_at' => $now, 'remove_requested_at' => null,
        ]);
        DB::table('node_access_peers')->insert([
            'id' => 'peer-quota-1', 'user_id' => 'user-member', 'node_id' => 'node-test-1',
            'device_id' => 'device-quota-1', 'entitlement_id' => 'entitlement-member-annual',
            'device_name' => 'Quota test device', 'public_key' => 'quota-peer-public-key',
            'device_key_fingerprint' => str_repeat('q', 64), 'generation' => 1,
            'device_binding_generation' => 1, 'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => 'encrypted-test-credential', 'profile_ciphertext' => 'encrypted-test-profile',
            'provision_request_id' => 'quota-provision-request', 'provision_acknowledgement_id' => 'quota-provision-ack',
            'provision_confirmation_status' => 'confirmed', 'status' => 'active',
            'upload_bytes' => 0, 'download_bytes' => 0,
            'quota_accounted_upload_bytes' => 0, 'quota_accounted_download_bytes' => 0,
            'last_observed_at' => null, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function createPeerOnNode(string $nodeId, string $peerId, string $deviceId): void
    {
        $now = now()->utc()->toIso8601String();
        DB::table('nodes')->insert([
            'id' => $nodeId, 'name' => $nodeId, 'region' => 'quota-test', 'protocol' => 'bridge',
            'endpoint_host' => '127.0.0.1', 'endpoint_port' => 9443, 'status' => 'available',
            'is_test' => false, 'max_sessions' => 10, 'active_sessions' => 0, 'latency_ms' => 1,
            'updated_at' => $now, 'health_status' => 'healthy', 'last_health_check_at' => $now,
            'config_version' => 1, 'agent_client_cert_sha256' => null,
        ]);
        DB::table('member_devices')->insert([
            'id' => $deviceId, 'user_id' => 'user-member', 'installation_id' => (string) Str::uuid(),
            'name' => $deviceId, 'platform' => 'android', 'status' => 'registered', 'revoked_at' => null,
            'created_at' => $now, 'updated_at' => $now, 'public_key' => $deviceId.'-public-key',
            'public_key_fingerprint' => hash('sha256', $deviceId), 'bound_at' => $now, 'remove_requested_at' => null,
        ]);
        DB::table('node_access_peers')->insert([
            'id' => $peerId, 'user_id' => 'user-member', 'node_id' => $nodeId,
            'device_id' => $deviceId, 'entitlement_id' => 'entitlement-member-annual',
            'device_name' => $deviceId, 'public_key' => $peerId.'-public-key',
            'device_key_fingerprint' => hash('sha256', $peerId), 'generation' => 1,
            'device_binding_generation' => 1, 'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => 'encrypted-test-credential', 'profile_ciphertext' => 'encrypted-test-profile',
            'provision_request_id' => $peerId.'-provision-request', 'provision_acknowledgement_id' => $peerId.'-provision-ack',
            'provision_confirmation_status' => 'confirmed', 'status' => 'active',
            'upload_bytes' => 0, 'download_bytes' => 0,
            'quota_accounted_upload_bytes' => 0, 'quota_accounted_download_bytes' => 0,
            'last_observed_at' => null, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return array{string,string,string} */
    private function createNodeClientCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'isolated-quota-node'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_x509_export($certificate, $pem));
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        $this->assertIsString($fingerprint);
        $this->assertTrue(openssl_pkey_export($key, $privateKeyPem));

        return [$pem, strtolower($fingerprint), $privateKeyPem];
    }
}
