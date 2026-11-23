<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NodeAccessRevocationProcessor;
use App\Services\NodePeerSubscriptionBindingDriver;
use App\Services\NodePeerSubscriptionBindingProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberDeviceSubscriptionTest extends TestCase
{
    private const SANDBOX_PROFILE = "# XLB sandbox-only peer profile; cannot connect to a service node.\nmixed-port: 17890\nallow-lan: false\nmode: rule\nproxies:\n  - name: XLB_SANDBOX_ONLY\n    type: socks5\n    server: 192.0.2.1\n    port: 1080\nproxy-groups:\n  - name: XLB_SANDBOX_ONLY\n    type: select\n    proxies:\n      - XLB_SANDBOX_ONLY\nrules:\n  - MATCH,XLB_SANDBOX_ONLY\n";

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['node_access.subscription_binding_enabled' => true, 'node_access.driver' => 'sandbox']);
    }

    public function test_profile_is_unavailable_until_the_node_confirms_the_current_subscription_generation(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');

        $pending = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertCreated()
            ->assertJsonPath('data.node_binding_status', 'pending')
            ->assertJsonPath('data.url', null)
            ->json('data');
        $token = Crypt::decryptString(DB::table('subscriptions')->where('id', $pending['id'])->value('token_encrypted'));
        $this->get('/subscribe/'.$token)->assertNotFound();
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', [
            'subscription_id' => $pending['id'], 'generation' => 1, 'status' => 'pending',
        ]);

        $this->assertSame(1, app(NodePeerSubscriptionBindingProcessor::class)->processDue());
        $ready = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertOk()
            ->assertJsonPath('data.node_binding_status', 'confirmed')
            ->json('data');

        $this->assertNotNull($ready['url']);
        $this->get($ready['url'])->assertOk()->assertHeader('X-XLB-Profile-Scope', 'local-sandbox-peer');
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', [
            'subscription_id' => $pending['id'], 'generation' => 1, 'status' => 'confirmed_test',
        ]);
    }

    public function test_subscription_binding_timeout_retries_with_the_same_request_id(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $pending = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertCreated()->json('data');
        $job = DB::table('node_access_subscription_binding_outbox')->where('subscription_id', $pending['id'])->first();
        $acknowledgement = [
            'node_id' => 'node-sandbox-peer-tests', 'peer_id' => 'peer-'.$deviceId,
            'request_id' => $job->id, 'subscription_id' => $pending['id'], 'generation' => 1,
            'acknowledgement_id' => 'agent-binding-ack-1', 'confirmation_status' => 'confirmed_test',
        ];
        $requestIds = [];
        $nodeExecuted = false;
        $driver = $this->mock(NodePeerSubscriptionBindingDriver::class);
        $driver->shouldReceive('bind')->twice()->andReturnUsing(
            function ($node, $peer, $subscription, string $requestId, string $token) use (&$requestIds, &$nodeExecuted, $acknowledgement, $job, $pending): array {
                $requestIds[] = $requestId;
                $this->assertSame($job->id, $requestId);
                $this->assertSame($pending['id'], $subscription->id);
                $this->assertNotSame('', $token);
                if (! $nodeExecuted) {
                    $nodeExecuted = true;
                    throw new \RuntimeException('response timed out after node execution');
                }

                return $acknowledgement;
            }
        );

        $processor = app(NodePeerSubscriptionBindingProcessor::class);
        $this->assertSame(1, $processor->processDue());
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', ['id' => $job->id, 'status' => 'retry']);
        $this->travel(11)->seconds();
        $this->assertSame(1, $processor->processDue());
        $this->assertSame([$job->id, $job->id], $requestIds);
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', [
            'id' => $job->id, 'status' => 'confirmed_test', 'acknowledgement_id' => 'agent-binding-ack-1',
        ]);

        $ready = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")->assertOk()->json('data');
        $this->assertNotNull($ready['url']);
    }

    public function test_late_binding_receipt_from_old_credential_generation_cannot_publish_rotated_url(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $pending = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertCreated()->json('data');
        $oldToken = Crypt::decryptString(DB::table('subscriptions')->where('id', $pending['id'])->value('token_encrypted'));
        $oldJob = DB::table('node_access_subscription_binding_outbox')
            ->where('subscription_id', $pending['id'])->where('generation', 1)->first();
        DB::table('node_access_subscription_binding_outbox')->where('id', '!=', $oldJob->id)
            ->update(['status' => 'superseded', 'next_attempt_at' => null]);

        $this->mock(NodePeerSubscriptionBindingDriver::class)
            ->shouldReceive('bind')->once()->andReturnUsing(
                function ($node, $peer, $subscription, string $requestId, string $token): array {
                    $this->assertNotSame('', $token);
                    $this->postJson("/api/v1/me/devices/{$peer->device_id}/subscription/rotate")
                        ->assertOk()->assertJsonPath('data.credential_generation', 2)
                        ->assertJsonPath('data.url', null);

                    return [
                        'node_id' => (string) $node->id,
                        'peer_id' => (string) $peer->id,
                        'request_id' => $requestId,
                        'subscription_id' => (string) $subscription->id,
                        'generation' => 1,
                        'acknowledgement_id' => 'late-generation-one-ack',
                        'confirmation_status' => 'confirmed_test',
                    ];
                },
            );

        $this->assertSame(1, app(NodePeerSubscriptionBindingProcessor::class)->processDue(1));
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', [
            'id' => $oldJob->id, 'generation' => 1, 'status' => 'superseded',
        ]);
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', [
            'subscription_id' => $pending['id'], 'generation' => 2, 'status' => 'pending',
        ]);
        $this->get('/subscribe/'.$oldToken)->assertNotFound();
        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)->assertJsonPath('error.code', 'device_peer_unavailable');
    }

    public function test_subscription_binding_is_superseded_and_peer_revoked_if_its_entitlement_changes_mid_request(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $pending = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertCreated()->json('data');
        $job = DB::table('node_access_subscription_binding_outbox')->where('subscription_id', $pending['id'])->first();
        $peer = DB::table('node_access_peers')->where('id', 'peer-'.$deviceId)->first();
        $oldEntitlementId = $peer->entitlement_id;
        $newEntitlementId = 'entitlement-member-renewal';
        $this->mock(NodePeerSubscriptionBindingDriver::class)->shouldReceive('bind')->once()
            ->andReturnUsing(function ($node, $peer, $subscription, string $requestId) use (
                $oldEntitlementId,
                $newEntitlementId,
            ): array {
                DB::table('entitlements')->where('id', $oldEntitlementId)->update(['status' => 'revoked_test']);
                DB::table('entitlements')->insert([
                    'id' => $newEntitlementId, 'user_id' => 'user-member', 'plan_id' => 'annual',
                    'status' => 'active', 'starts_at' => now()->subMinute()->utc()->toIso8601String(),
                    'ends_at' => now()->addYear()->utc()->toIso8601String(), 'data_used_gb' => 0,
                ]);

                return [
                    'node_id' => (string) $node->id, 'peer_id' => (string) $peer->id,
                    'request_id' => $requestId, 'subscription_id' => (string) $subscription->id,
                    'generation' => 1, 'acknowledgement_id' => 'stale-entitlement-binding-ack',
                    'confirmation_status' => 'confirmed_test',
                ];
            });

        $this->assertSame(1, app(NodePeerSubscriptionBindingProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_subscription_binding_outbox', [
            'id' => $job->id, 'status' => 'superseded', 'acknowledgement_id' => 'stale-entitlement-binding-ack',
        ]);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peer->id, 'status' => 'revocation_pending', 'entitlement_id' => $oldEntitlementId,
        ]);
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'peer_id' => $peer->id, 'status' => 'pending', 'reason' => 'entitlement_lost',
        ]);
        $this->assertDatabaseHas('entitlements', ['id' => $newEntitlementId, 'status' => 'active']);
        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)->assertJsonPath('error.code', 'device_peer_unavailable');
    }

    public function test_each_registered_device_gets_one_stable_independent_subscription(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $firstDeviceId = $this->registerDevice('Clash Verge 主机', 'windows');
        $secondDeviceId = $this->registerDevice('备用电脑', 'macos');

        $this->postJson("/api/v1/me/devices/{$firstDeviceId}/subscription")->assertCreated();
        $this->postJson("/api/v1/me/devices/{$secondDeviceId}/subscription")->assertCreated();
        $this->assertSame(2, app(NodePeerSubscriptionBindingProcessor::class)->processDue());
        $first = $this->postJson("/api/v1/me/devices/{$firstDeviceId}/subscription")
            ->assertOk()->assertJsonPath('data.device_id', $firstDeviceId)
            ->assertJsonPath('data.requires_reimport', false)->json('data');
        $retry = $this->postJson("/api/v1/me/devices/{$firstDeviceId}/subscription")
            ->assertOk()
            ->json('data');
        $second = $this->postJson("/api/v1/me/devices/{$secondDeviceId}/subscription")->assertOk()->json('data');

        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame($first['url'], $retry['url']);
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertDatabaseCount('subscriptions', 2);
        $this->getJson('/api/v1/me/subscriptions')->assertOk()->assertJsonFragment([
            'device_id' => $firstDeviceId,
            'url' => $first['url'],
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $first['id'],
            'user_id' => 'user-member',
            'member_device_id' => $firstDeviceId,
            'node_access_peer_id' => 'peer-'.$firstDeviceId,
            'node_access_peer_generation' => 1,
            'member_device_generation' => 1,
            'status' => 'active',
        ]);
        $stored = DB::table('subscriptions')->where('id', $first['id'])->first();
        $token = basename(parse_url($first['url'], PHP_URL_PATH));
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertNotSame($token, $stored->token_encrypted);
        $this->assertSame($token, Crypt::decryptString($stored->token_encrypted));
    }

    public function test_rotation_invalidates_old_url_and_requires_third_party_reimport(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);
        $this->get($subscription['url'])->assertOk();

        $rotated = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription/rotate")
            ->assertOk()
            ->assertJsonPath('data.requires_reimport', true)
            ->assertJsonPath('data.credential_generation', 2)
            ->json('data');

        $this->assertNull($rotated['url']);
        $this->get($subscription['url'])->assertNotFound();
        $this->assertSame(1, app(NodePeerSubscriptionBindingProcessor::class)->processDue());
        $rotated = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")->assertOk()->json('data');
        $this->assertNotSame($subscription['url'], $rotated['url']);
        $this->get($rotated['url'])->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_device_removal_immediately_invalidates_its_subscription_url(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);

        $this->deleteJson("/api/v1/me/devices/{$deviceId}")->assertAccepted()
            ->assertJsonPath('data.status', 'revocation_pending');
        $this->get($subscription['url'])->assertNotFound();
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription['id'],
            'status' => 'revoked',
            'token_encrypted' => null,
        ]);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => 'peer-'.$deviceId,
            'status' => 'revocation_pending',
        ]);
        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription/revoke")->assertAccepted()
            ->assertJsonPath('data.status', 'revocation_pending');
    }

    public function test_revoked_credential_waits_for_node_receipt_before_reissue(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $first = $this->createReadySubscription($deviceId);
        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription/revoke")->assertAccepted()
            ->assertJsonPath('data.status', 'revocation_pending');
        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription/revoke")->assertAccepted()
            ->assertJsonPath('data.status', 'revocation_pending');
        $this->assertDatabaseCount('node_access_revocation_outbox', 1);
        $this->get($first['url'])->assertNotFound();
        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)->assertJsonPath('error.code', 'device_peer_unavailable');

        config(['node_access.enabled' => true, 'node_access.driver' => 'sandbox']);
        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_peers', ['id' => 'peer-'.$deviceId, 'status' => 'revoked']);
        $nextPeerId = $this->addSandboxPeerForDevice($deviceId);

        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")->assertOk()
            ->assertJsonPath('data.node_binding_status', 'pending');
        $this->assertSame(1, app(NodePeerSubscriptionBindingProcessor::class)->processDue());
        $second = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertOk()->assertJsonPath('data.credential_generation', 2)
            ->assertJsonPath('data.requires_reimport', false)->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertDatabaseHas('subscriptions', ['id' => $second['id'], 'node_access_peer_id' => $nextPeerId]);
        $this->get($first['url'])->assertNotFound();
        $this->get($second['url'])->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_current_registered_device_count_is_rechecked_against_plan_limit(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $this->registerDevice('备用电脑', 'macos');
        DB::table('plans')->where('id', 'annual')->update(['max_devices' => 1]);

        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_limit_reached');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_expired_member_cannot_fetch_a_device_profile(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);
        DB::table('entitlements')->where('user_id', 'user-member')
            ->update(['ends_at' => now()->subSecond()->utc()->toIso8601String()]);

        $this->get($subscription['url'])->assertNotFound();
    }

    public function test_subscription_management_is_scoped_to_the_registered_device_owner(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');

        $this->actingAs(User::findOrFail('user-admin'))
            ->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertNotFound();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_device_subscription_is_not_issued_until_production_device_proof_is_supported(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        config(['subscription_routes.scope' => 'production']);

        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'third_party_device_binding_unavailable');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_unbound_device_cannot_receive_a_device_subscription(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Unbound device', 'windows');
        DB::table('member_devices')->where('id', $deviceId)->update([
            'public_key' => null,
            'public_key_fingerprint' => null,
            'bound_at' => null,
        ]);

        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_binding_required');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_profile_stops_serving_when_its_bound_peer_generation_changes(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);
        $profile = $this->get($subscription['url'])->assertOk()->assertHeader('X-XLB-Profile-Scope', 'local-sandbox-peer');
        $this->assertSame(self::SANDBOX_PROFILE, $profile->getContent());
        $this->assertStringNotContainsString('198.51.100.10', $profile->getContent());

        DB::table('node_access_peers')->where('id', 'peer-'.$deviceId)->update([
            'generation' => 2,
            'status' => 'revocation_pending',
        ]);

        $this->get($subscription['url'])->assertNotFound();
    }

    public function test_profile_stops_serving_when_the_device_binding_generation_changes(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);

        DB::table('member_devices')->where('id', $deviceId)->increment('binding_generation');

        $this->get($subscription['url'])->assertNotFound();
    }

    public function test_profile_stops_serving_when_its_peer_entitlement_is_replaced(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);
        $peer = DB::table('node_access_peers')->where('id', 'peer-'.$deviceId)->first();
        $oldEntitlementId = $peer->entitlement_id;

        DB::table('entitlements')->insert([
            'id' => 'entitlement-member-renewal', 'user_id' => 'user-member', 'plan_id' => 'annual',
            'status' => 'active', 'starts_at' => now()->subMinute()->utc()->toIso8601String(),
            'ends_at' => now()->addYear()->utc()->toIso8601String(), 'data_used_gb' => 0,
        ]);
        DB::table('entitlements')->where('id', $oldEntitlementId)->update(['status' => 'revoked_test']);

        $this->get($subscription['url'])->assertNotFound();
    }

    public function test_existing_subscription_is_not_returned_after_its_peer_generation_changes(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        $subscription = $this->createReadySubscription($deviceId);
        DB::table('node_access_peers')->where('id', 'peer-'.$deviceId)->increment('generation');

        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)->assertJsonPath('error.code', 'device_peer_binding_stale');
        $this->get($subscription['url'])->assertNotFound();
    }

    public function test_profile_rejects_a_peer_owned_by_another_device(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $firstDeviceId = $this->registerDevice('Clash Verge A', 'windows');
        $secondDeviceId = $this->registerDevice('Clash Verge B', 'macos');
        $subscription = $this->createReadySubscription($firstDeviceId);
        DB::table('subscriptions')->where('id', $subscription['id'])->update([
            'node_access_peer_id' => 'peer-'.$secondDeviceId,
        ]);

        $this->get($subscription['url'])->assertNotFound();
    }

    public function test_device_subscription_requires_active_entitlement_and_registered_device(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->registerDevice('Clash Verge', 'windows');
        DB::table('entitlements')->where('user_id', 'user-member')->update(['status' => 'revoked_test']);

        $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'active_entitlement_required');
        $this->postJson('/api/v1/me/devices/missing-device/subscription')
            ->assertNotFound();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    private function registerDevice(string $name, string $platform): string
    {
        $deviceId = $this->postJson('/api/v1/me/devices', [
            'installation_id' => fake()->uuid(),
            'name' => $name,
            'platform' => $platform,
        ])->assertCreated()->json('data.id');

        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $publicKeyDer = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']), true);
        $this->assertNotFalse($publicKeyDer);
        $publicKey = rtrim(strtr(base64_encode($publicKeyDer), '+/', '-_'), '=');
        $fingerprint = hash('sha256', $publicKeyDer);
        $now = now()->utc()->toIso8601String();
        DB::table('nodes')->updateOrInsert(['id' => 'node-sandbox-peer-tests'], [
            'name' => '隔离 peer 沙箱',
            'region' => '本地测试',
            'protocol' => 'FAKE',
            'endpoint_host' => '192.0.2.1',
            'endpoint_port' => 1080,
            'status' => 'available',
            'is_test' => true,
            'max_sessions' => 100,
            'active_sessions' => 0,
            'latency_ms' => null,
            'health_status' => 'verified_test',
            'last_health_check_at' => $now,
            'config_version' => 1,
            'updated_at' => $now,
        ]);
        DB::table('member_devices')->where('id', $deviceId)->update([
            'public_key' => $publicKey,
            'public_key_fingerprint' => $fingerprint,
            'bound_at' => $now,
        ]);
        DB::table('node_access_peers')->insert([
            'id' => 'peer-'.$deviceId,
            'user_id' => 'user-member',
            'node_id' => 'node-sandbox-peer-tests',
            'device_id' => $deviceId,
            'entitlement_id' => DB::table('entitlements')->where('user_id', 'user-member')->value('id'),
            'device_name' => $name,
            'public_key' => $publicKey,
            'status' => 'active',
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'last_observed_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'generation' => 1,
            'device_key_fingerprint' => $fingerprint,
            'device_binding_generation' => 1,
            'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => Crypt::encryptString('peer-secret-'.$deviceId),
            'profile_ciphertext' => Crypt::encryptString(self::SANDBOX_PROFILE),
            'profile_sha256' => hash('sha256', self::SANDBOX_PROFILE),
            'profile_format' => 'sandbox-only',
            'profile_renderer_id' => 'isolated-test-agent',
            'profile_renderer_version' => '0',
            'provision_request_id' => fake()->uuid(),
            'provision_acknowledgement_id' => 'ack-'.$deviceId,
            'provision_confirmation_status' => 'confirmed_test',
        ]);

        return $deviceId;
    }

    /** @return array<string, mixed> */
    private function createReadySubscription(string $deviceId): array
    {
        $response = $this->postJson("/api/v1/me/devices/{$deviceId}/subscription");
        $response->assertSuccessful();
        if ($response->json('data.node_binding_status') === 'pending') {
            app(NodePeerSubscriptionBindingProcessor::class)->processDue();
        }

        return $this->postJson("/api/v1/me/devices/{$deviceId}/subscription")
            ->assertSuccessful()->assertJsonPath('data.node_binding_status', 'confirmed')->json('data');
    }

    private function addSandboxPeerForDevice(string $deviceId): string
    {
        $device = DB::table('member_devices')->where('id', $deviceId)->first();
        $this->assertNotNull($device);
        $peerId = 'peer-'.fake()->uuid();
        $now = now()->utc()->toIso8601String();

        DB::table('node_access_peers')->insert([
            'id' => $peerId,
            'user_id' => $device->user_id,
            'node_id' => 'node-sandbox-peer-tests',
            'device_id' => $deviceId,
            'entitlement_id' => DB::table('entitlements')->where('user_id', $device->user_id)->value('id'),
            'device_name' => $device->name,
            'public_key' => $device->public_key,
            'status' => 'active',
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'last_observed_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'generation' => 1,
            'device_key_fingerprint' => $device->public_key_fingerprint,
            'device_binding_generation' => (int) $device->binding_generation,
            'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => Crypt::encryptString('peer-secret-'.$peerId),
            'profile_ciphertext' => Crypt::encryptString(self::SANDBOX_PROFILE),
            'profile_sha256' => hash('sha256', self::SANDBOX_PROFILE),
            'profile_format' => 'sandbox-only',
            'profile_renderer_id' => 'isolated-test-agent',
            'profile_renderer_version' => '0',
            'provision_request_id' => fake()->uuid(),
            'provision_acknowledgement_id' => 'ack-'.$peerId,
            'provision_confirmation_status' => 'confirmed_test',
        ]);

        return $peerId;
    }
}
