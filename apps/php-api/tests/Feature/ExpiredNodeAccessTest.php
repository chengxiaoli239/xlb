<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExpiredNodeAccessReconciler;
use App\Services\NodeAccessRevocationProcessor;
use App\Services\NodePeerRevocationDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpiredNodeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $now = now()->utc()->toIso8601String();
        DB::table('node_access_peers')->insert([
            'id' => 'peer-expiry-1', 'user_id' => 'user-member', 'node_id' => 'node-test-1',
            'device_id' => 'device-expiry-1', 'device_name' => 'Android test device',
            'public_key' => 'public-peer-expiry-1', 'device_key_fingerprint' => str_repeat('a', 64),
            'generation' => 1, 'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => encrypt('peer-expiry-credential'), 'profile_ciphertext' => null,
            'provision_request_id' => 'provision-expiry-1', 'status' => 'active',
            'upload_bytes' => 100, 'download_bytes' => 200,
            'last_observed_at' => $now, 'revoked_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_active_entitlement_keeps_peer_and_expiry_queues_idempotent_revocation(): void
    {
        $reconciler = app(ExpiredNodeAccessReconciler::class);
        $this->assertSame(0, $reconciler->queueRevocations());
        DB::table('entitlements')->where('user_id', 'user-member')
            ->update(['ends_at' => now()->subSecond()->utc()->toIso8601String()]);

        $this->assertSame(1, $reconciler->queueRevocations());
        $this->assertSame(0, $reconciler->queueRevocations());
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'peer_id' => 'peer-expiry-1', 'refund_event_id' => null,
            'reason' => 'entitlement_lost', 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => 'peer-expiry-1', 'status' => 'revocation_pending',
        ]);
        $this->actingAs(User::findOrFail('user-admin'));
        $this->getJson('/api/v1/admin/summary')->assertOk()
            ->assertJsonPath('data.pendingNodeRevocations', 1);

        $this->assertSame(0, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', [
            'id' => 'peer-expiry-1', 'status' => 'revocation_pending',
        ]);
    }

    public function test_expired_peer_is_not_called_revoked_until_node_acknowledges(): void
    {
        DB::table('entitlements')->where('user_id', 'user-member')
            ->update(['ends_at' => now()->subSecond()->utc()->toIso8601String()]);
        app(ExpiredNodeAccessReconciler::class)->queueRevocations();
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', 'peer-expiry-1')->first();
        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $driver->shouldReceive('revoke')->once()->withArgs(function ($node, $peer, string $requestId, int $generation) use ($job): bool {
            return $node->id === 'node-test-1' && $peer->id === 'peer-expiry-1'
                && $requestId === $job->id && $generation === (int) $job->generation;
        })->andReturn([
            'node_id' => 'node-test-1', 'peer_id' => 'peer-expiry-1', 'request_id' => $job->id,
            'generation' => (int) $job->generation,
            'acknowledgement_id' => 'test-expiry-ack',
            'upload_bytes' => 120, 'download_bytes' => 250,
            'confirmation_status' => 'confirmed_test',
        ]);

        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', [
            'id' => 'peer-expiry-1', 'status' => 'revoked',
            'upload_bytes' => 120, 'download_bytes' => 250,
        ]);
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'peer_id' => 'peer-expiry-1', 'status' => 'confirmed_test',
            'acknowledgement_id' => 'test-expiry-ack',
        ]);
    }

    public function test_wrong_node_acknowledgement_does_not_mark_peer_revoked(): void
    {
        DB::table('entitlements')->where('user_id', 'user-member')
            ->update(['ends_at' => now()->subSecond()->utc()->toIso8601String()]);
        app(ExpiredNodeAccessReconciler::class)->queueRevocations();
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', 'peer-expiry-1')->first();
        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $driver->shouldReceive('revoke')->once()->andReturn([
            'node_id' => 'node-other', 'peer_id' => 'peer-expiry-1', 'request_id' => $job->id, 'generation' => 2,
            'acknowledgement_id' => 'wrong-node-ack', 'upload_bytes' => 120, 'download_bytes' => 250,
            'confirmation_status' => 'confirmed_test',
        ]);

        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', ['id' => 'peer-expiry-1', 'status' => 'revocation_pending']);
        $this->assertDatabaseHas('node_access_revocation_outbox', ['id' => $job->id, 'status' => 'retry']);
    }

    public function test_revocation_final_counters_never_reduce_recorded_usage(): void
    {
        DB::table('entitlements')->where('user_id', 'user-member')
            ->update(['ends_at' => now()->subSecond()->utc()->toIso8601String()]);
        app(ExpiredNodeAccessReconciler::class)->queueRevocations();
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', 'peer-expiry-1')->first();
        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $driver->shouldReceive('revoke')->once()->andReturn([
            'node_id' => 'node-test-1', 'peer_id' => 'peer-expiry-1', 'request_id' => $job->id, 'generation' => 2,
            'acknowledgement_id' => 'monotonic-counter-ack', 'upload_bytes' => 50, 'download_bytes' => 100,
            'confirmation_status' => 'confirmed_test',
        ]);

        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', [
            'id' => 'peer-expiry-1', 'status' => 'revoked', 'upload_bytes' => 100, 'download_bytes' => 200,
        ]);
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'id' => $job->id, 'final_upload_bytes' => 100, 'final_download_bytes' => 200,
        ]);
    }

    public function test_revocation_timeout_retries_with_the_same_request_and_generation(): void
    {
        DB::table('entitlements')->where('user_id', 'user-member')
            ->update(['ends_at' => now()->subSecond()->utc()->toIso8601String()]);
        app(ExpiredNodeAccessReconciler::class)->queueRevocations();
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', 'peer-expiry-1')->first();
        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $observedRequestIds = [];
        $nodeExecuted = false;
        $acknowledgement = [
            'node_id' => 'node-test-1', 'peer_id' => 'peer-expiry-1', 'request_id' => $job->id,
            'generation' => (int) $job->generation, 'acknowledgement_id' => 'ack-after-timeout',
            'upload_bytes' => 120, 'download_bytes' => 250, 'confirmation_status' => 'confirmed_test',
        ];
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $driver->shouldReceive('revoke')->twice()->andReturnUsing(
            function ($node, $peer, string $requestId, int $generation) use (&$observedRequestIds, &$nodeExecuted, $acknowledgement): array {
                $observedRequestIds[] = $requestId;
                $this->assertSame('node-test-1', $node->id);
                $this->assertSame('peer-expiry-1', $peer->id);
                $this->assertSame(2, $generation);
                if (! $nodeExecuted) {
                    $nodeExecuted = true;
                    throw new \RuntimeException('response timed out after node execution');
                }

                return $acknowledgement;
            }
        );
        $processor = app(NodeAccessRevocationProcessor::class);

        $this->assertSame(1, $processor->processDue());
        $this->assertDatabaseHas('node_access_peers', ['id' => 'peer-expiry-1', 'status' => 'revocation_pending']);
        $this->assertDatabaseHas('node_access_revocation_outbox', ['id' => $job->id, 'status' => 'retry']);
        $this->travel(11)->seconds();
        $this->assertSame(1, $processor->processDue());

        $this->assertSame([$job->id, $job->id], $observedRequestIds);
        $this->assertDatabaseHas('node_access_peers', ['id' => 'peer-expiry-1', 'status' => 'revoked']);
        $this->assertDatabaseHas('node_access_revocation_outbox', ['id' => $job->id, 'status' => 'confirmed_test']);
    }

    public function test_exhausted_traffic_also_queues_peer_revocation(): void
    {
        DB::table('entitlements')->where('user_id', 'user-member')->update(['data_used_gb' => 1500]);

        $this->assertSame(1, app(ExpiredNodeAccessReconciler::class)->queueRevocations());
        $this->assertDatabaseHas('node_access_peers', [
            'id' => 'peer-expiry-1', 'status' => 'revocation_pending',
        ]);
    }
}
