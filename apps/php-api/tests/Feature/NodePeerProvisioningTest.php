<?php

namespace Tests\Feature;

use App\Services\NodePeerProvisioningDriver;
use App\Services\NodePeerProvisioningProcessor;
use App\Services\NodePeerProvisioningService;
use App\Services\NodePeerRevocationRequestService;
use App\Services\SandboxNodePeerProvisioningDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    public function test_duplicate_provision_requests_reuse_the_same_peer_and_request_id(): void
    {
        $this->prepareProductionRouteAndDevice();
        $this->mock(NodePeerProvisioningDriver::class);
        $service = app(NodePeerProvisioningService::class);

        $first = $service->ensureForProvenDevice('user-member', 'device-provision-1', str_repeat('d', 64));
        $second = $service->ensureForProvenDevice('user-member', 'device-provision-1', str_repeat('d', 64));

        $this->assertSame('provisioning', $first['status']);
        $this->assertSame('provisioning', $second['status']);
        $this->assertDatabaseCount('node_access_peers', 1);
        $this->assertDatabaseCount('node_access_provisioning_outbox', 1);
        $peer = DB::table('node_access_peers')->first();
        $job = DB::table('node_access_provisioning_outbox')->first();
        $this->assertSame(1, (int) $peer->device_binding_generation);
        $this->assertSame(str_repeat('d', 64), $peer->device_key_fingerprint);
        $this->assertSame($peer->provision_request_id, $job->id);
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

    private function insertProvisioningPeerAndJob(): string
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
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return 'peer-provision-1';
    }
}
