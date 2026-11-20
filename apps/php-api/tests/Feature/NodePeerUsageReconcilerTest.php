<?php

namespace Tests\Feature;

use App\Services\NodeAccessRevocationProcessor;
use App\Services\NodePeerRevocationDriver;
use App\Services\NodePeerRevocationRequestService;
use App\Services\NodePeerUsageDriver;
use App\Services\NodePeerUsageReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NodePeerUsageReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'is_test' => false,
            'health_status' => 'healthy',
            'agent_endpoint' => 'https://node-agent.example.invalid',
        ]);
    }

    public function test_repeated_node_usage_snapshots_are_monotonic_and_idempotently_billed(): void
    {
        $peerId = $this->createPeer();
        config(['node_access.usage_reconciliation_enabled' => true]);
        $baseline = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');
        $snapshot = $this->usageSnapshot($peerId, 1024, 2048);
        $driver = $this->mock(NodePeerUsageDriver::class);
        $driver->shouldReceive('usage')->twice()->andReturn($snapshot, $snapshot);

        $reconciler = app(NodePeerUsageReconciler::class);
        $this->assertSame(1, $reconciler->reconcile());
        $firstUsed = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');
        $this->assertSame(1, $reconciler->reconcile());
        $secondUsed = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');

        $this->assertEqualsWithDelta($baseline + (3072 / (1024 ** 3)), $firstUsed, 1e-10);
        $this->assertSame($firstUsed, $secondUsed);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peerId, 'upload_bytes' => 1024, 'download_bytes' => 2048,
        ]);
    }

    public function test_counter_delta_overflow_is_rejected_without_changing_usage_or_peer_watermark(): void
    {
        $peerId = $this->createPeer();
        config(['node_access.usage_reconciliation_enabled' => true]);
        $baseline = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');
        $this->mock(NodePeerUsageDriver::class)->shouldReceive('usage')->once()
            ->andReturn($this->usageSnapshot($peerId, PHP_INT_MAX, 1));

        $this->assertSame(0, app(NodePeerUsageReconciler::class)->reconcile());
        $this->assertSame($baseline, (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb'));
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peerId, 'upload_bytes' => 0, 'download_bytes' => 0,
        ]);
    }

    public function test_exhausted_metered_entitlement_queues_revocation_and_keeps_device_slot_pending(): void
    {
        $peerId = $this->createPeer();
        DB::table('plans')->where('id', 'annual')->update(['traffic_gb' => 0.001]);
        DB::table('entitlements')->where('user_id', 'user-member')->update(['data_used_gb' => 0]);
        config(['node_access.usage_reconciliation_enabled' => true]);
        $this->mock(NodePeerUsageDriver::class)->shouldReceive('usage')->once()
            ->andReturn($this->usageSnapshot($peerId, 2 * 1024 * 1024, 0));

        $this->assertSame(1, app(NodePeerUsageReconciler::class)->reconcile());
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'revocation_pending']);
        $this->assertDatabaseHas('node_access_revocation_outbox', ['peer_id' => $peerId, 'status' => 'pending']);
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'upload_bytes' => 2097152]);
        $this->assertGreaterThanOrEqual(0.001, (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb'));
    }

    public function test_final_revocation_receipt_settles_only_the_unobserved_bytes(): void
    {
        $peerId = $this->createPeer();
        config(['node_access.enabled' => true, 'node_access.driver' => 'agent']);
        $baseline = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');
        app(NodePeerRevocationRequestService::class)->request(
            DB::table('node_access_peers')->where('id', $peerId)->first(),
            'device_removed',
        );
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', $peerId)->first();
        $this->mock(NodePeerRevocationDriver::class)->shouldReceive('revoke')->once()->andReturn([
            'node_id' => 'node-test-1', 'peer_id' => $peerId, 'request_id' => $job->id,
            'generation' => (int) $job->generation, 'acknowledgement_id' => 'final-usage-ack',
            'upload_bytes' => 5000, 'download_bytes' => 7000, 'confirmation_status' => 'confirmed_test',
        ]);

        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $usedAfterReceipt = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');
        $this->assertSame(0, app(NodeAccessRevocationProcessor::class)->processDue());
        $usedAfterRetry = (float) DB::table('entitlements')->where('user_id', 'user-member')->value('data_used_gb');

        $this->assertEqualsWithDelta($baseline + (12000 / (1024 ** 3)), $usedAfterReceipt, 1e-10);
        $this->assertSame($usedAfterReceipt, $usedAfterRetry);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peerId, 'status' => 'revoked', 'upload_bytes' => 5000, 'download_bytes' => 7000,
        ]);
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'id' => $job->id, 'status' => 'confirmed_test',
            'final_upload_bytes' => 5000, 'final_download_bytes' => 7000,
        ]);
    }

    private function createPeer(): string
    {
        $now = now()->utc()->toIso8601String();
        $entitlementId = DB::table('entitlements')->where('user_id', 'user-member')->value('id');
        DB::table('member_devices')->insert([
            'id' => 'device-usage-1', 'user_id' => 'user-member',
            'installation_id' => '7d53df62-4bb1-464e-89c2-1d314501fbf3', 'name' => 'Usage test device',
            'platform' => 'windows', 'status' => 'registered', 'revoked_at' => null,
            'created_at' => $now, 'updated_at' => $now, 'public_key' => 'usage-device-public-key',
            'public_key_fingerprint' => str_repeat('c', 64), 'bound_at' => $now, 'remove_requested_at' => null,
        ]);
        DB::table('node_access_peers')->insert([
            'id' => 'peer-usage-1', 'user_id' => 'user-member', 'node_id' => 'node-test-1',
            'device_id' => 'device-usage-1', 'entitlement_id' => $entitlementId, 'device_name' => 'Usage test device',
            'public_key' => 'usage-peer-public-key', 'device_key_fingerprint' => str_repeat('c', 64),
            'generation' => 1, 'device_binding_generation' => 1,
            'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => Crypt::encryptString('usage-peer-secret'),
            'profile_ciphertext' => Crypt::encryptString('sandbox profile'),
            'provision_request_id' => 'usage-provision-request', 'provision_acknowledgement_id' => 'usage-provision-ack',
            'provision_confirmation_status' => 'confirmed', 'status' => 'active',
            'upload_bytes' => 0, 'download_bytes' => 0, 'last_observed_at' => null,
            'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return 'peer-usage-1';
    }

    /** @return array<string, int|string> */
    private function usageSnapshot(string $peerId, int $uploadBytes, int $downloadBytes): array
    {
        return [
            'node_id' => 'node-test-1', 'peer_id' => $peerId, 'generation' => 1, 'status' => 'active',
            'upload_bytes' => $uploadBytes, 'download_bytes' => $downloadBytes,
        ];
    }
}
