<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NodeAccessRevocationProcessor;
use App\Services\NodePeerProvisioningDriver;
use App\Services\NodePeerProvisioningProcessor;
use App\Services\NodePeerProvisioningService;
use App\Services\NodePeerRevocationDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class MemberDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_member_can_register_a_device_idempotently_and_view_quota(): void
    {
        $this->actingAs(User::findOrFail('user-member'));

        $this->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonPath('data.entitlement_active', true)
            ->assertJsonPath('data.max_devices', 5)
            ->assertJsonPath('data.registered_count', 0)
            ->assertJsonPath('data.available_slots', 5)
            ->assertJsonCount(0, 'data.devices');

        $registration = [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'name' => '我的 Android 手机',
            'platform' => 'android',
        ];

        $device = $this->postJson('/api/v1/me/devices', $registration)
            ->assertCreated()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.name', '我的 Android 手机')
            ->assertJsonMissingPath('data.connected')
            ->json('data');

        $retry = $this->postJson('/api/v1/me/devices', $registration)
            ->assertOk()
            ->json('data');

        $this->assertSame($device['id'], $retry['id']);
        $this->getJson('/api/v1/me/devices')
            ->assertJsonPath('data.registered_count', 1)
            ->assertJsonPath('data.available_slots', 4)
            ->assertJsonCount(1, 'data.devices');
        $this->assertDatabaseCount('member_devices', 1);
    }

    public function test_registration_requires_active_entitlement_and_obeys_device_limit(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        DB::table('plans')->where('id', 'annual')->update(['max_devices' => 1]);

        $this->postJson('/api/v1/me/devices', [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'name' => 'Android 手机',
            'platform' => 'android',
        ])->assertCreated();

        $this->postJson('/api/v1/me/devices', [
            'installation_id' => '7be684d8-b65a-4298-9060-25f85cf8ae19',
            'name' => 'Windows 电脑',
            'platform' => 'windows',
        ])->assertStatus(409)->assertJsonPath('error.code', 'device_limit_reached');

        DB::table('entitlements')->where('user_id', 'user-member')->delete();
        $this->postJson('/api/v1/me/devices', [
            'installation_id' => 'cfcb24d3-d51a-4e93-89c7-f643fc9b338d',
            'name' => 'iPhone',
            'platform' => 'ios',
        ])->assertStatus(409)->assertJsonPath('error.code', 'active_entitlement_required');
    }

    public function test_device_binding_verifies_a_device_key_and_returns_no_account_wide_subscription(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $keyPair = $this->deviceKeyPair();

        $response = $this->authorizeDevice($keyPair);

        $response->assertCreated()
            ->assertJsonPath('data.device.status', 'registered')
            ->assertJsonPath('data.device.bound', true)
            ->assertJsonPath('data.node_authorization.status', 'unavailable')
            ->assertJsonMissingPath('data.subscription_url')
            ->assertJsonMissingPath('data.profile');
        $this->assertDatabaseHas('member_devices', [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'public_key_fingerprint' => hash('sha256', $keyPair['public_key_der']),
        ]);
    }

    public function test_device_binding_proof_cannot_be_replayed_or_signed_by_another_key(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $keyPair = $this->deviceKeyPair();
        $otherKeyPair = $this->deviceKeyPair();
        $challenge = $this->createBindingChallenge($keyPair);
        $signature = $this->signChallenge($challenge['challenge'], $otherKeyPair['private_key']);

        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $signature,
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'device_binding_proof_invalid')
            ->assertJsonMissingPath('data.node_authorization.peer_credential');

        $validSignature = $this->signChallenge($challenge['challenge'], $keyPair['private_key']);
        $payload = ['challenge_id' => $challenge['challenge_id'], 'signature' => $validSignature];
        $this->postJson('/api/v1/me/device-bindings/authorize', $payload)->assertCreated();
        $this->postJson('/api/v1/me/device-bindings/authorize', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_binding_challenge_used');
    }

    public function test_device_binding_challenge_expires_and_requires_active_entitlement(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $keyPair = $this->deviceKeyPair();
        $challenge = $this->createBindingChallenge($keyPair);
        $this->travel(6)->minutes();
        $signature = $this->signChallenge($challenge['challenge'], $keyPair['private_key']);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $signature,
        ])->assertStatus(409)->assertJsonPath('error.code', 'device_binding_challenge_expired');

        DB::table('entitlements')->where('user_id', 'user-member')->update(['status' => 'revoked_test']);
        $this->postJson('/api/v1/me/device-bindings/challenge', $this->bindingPayload($this->deviceKeyPair()))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'active_entitlement_required');
    }

    public function test_device_binding_rechecks_entitlement_before_authorizing_after_refund(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $keyPair = $this->deviceKeyPair();
        $challenge = $this->createBindingChallenge($keyPair);
        DB::table('entitlements')->where('user_id', 'user-member')->update(['status' => 'revoked_test']);
        $signature = $this->signChallenge($challenge['challenge'], $keyPair['private_key']);

        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $signature,
        ])->assertStatus(409)->assertJsonPath('error.code', 'active_entitlement_required');
        $this->assertDatabaseCount('member_devices', 0);
    }

    public function test_device_binding_rejects_suspended_accounts_and_exhausted_traffic(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $keyPair = $this->deviceKeyPair();
        $challenge = $this->createBindingChallenge($keyPair);
        DB::table('users')->where('id', 'user-member')->update(['status' => 'disabled']);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $this->signChallenge($challenge['challenge'], $keyPair['private_key']),
        ])->assertStatus(409)->assertJsonPath('error.code', 'active_entitlement_required');
        DB::table('users')->where('id', 'user-member')->update(['status' => 'active']);
        DB::table('entitlements')->where('user_id', 'user-member')->update(['data_used_gb' => 1500]);
        $this->postJson('/api/v1/me/device-bindings/challenge', $this->bindingPayload($keyPair))
            ->assertStatus(409)->assertJsonPath('error.code', 'active_entitlement_required');
        $this->assertDatabaseCount('member_devices', 0);
    }

    public function test_device_binding_uses_the_shared_account_device_quota(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        DB::table('plans')->where('id', 'annual')->update(['max_devices' => 1]);
        $this->authorizeDevice($this->deviceKeyPair())->assertCreated();

        $this->authorizeDevice($this->deviceKeyPair(), '7be684d8-b65a-4298-9060-25f85cf8ae19')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_limit_reached');
        $this->assertDatabaseCount('member_devices', 1);
    }

    public function test_device_binding_is_idempotent_but_rejects_key_replacement_and_revoked_installations(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $keyPair = $this->deviceKeyPair();
        $first = $this->authorizeDevice($keyPair)->assertCreated()->json('data.device.id');
        $this->authorizeDevice($keyPair)->assertOk()->assertJsonPath('data.device.id', $first);
        $this->postJson('/api/v1/me/device-bindings/challenge', $this->bindingPayload($this->deviceKeyPair()))
            ->assertStatus(409)->assertJsonPath('error.code', 'device_key_mismatch');
        $this->deleteJson('/api/v1/me/devices/'.$first)->assertNoContent();
        $this->postJson('/api/v1/me/device-bindings/challenge', $this->bindingPayload($keyPair))
            ->assertStatus(409)->assertJsonPath('error.code', 'device_registration_revoked');
        $this->assertDatabaseCount('member_devices', 1);
    }

    public function test_isolated_test_profile_is_never_delivered_as_a_member_profile(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        config(['node_access.provisioning_enabled' => true]);
        $this->prepareProductionRoute();
        $keyPair = $this->deviceKeyPair();
        $driver = $this->mock(NodePeerProvisioningDriver::class);

        $this->authorizeDevice($keyPair)
            ->assertCreated()
            ->assertJsonPath('data.node_authorization.status', 'provisioning')
            ->assertJsonMissingPath('data.node_authorization.profile')
            ->assertJsonMissingPath('data.node_authorization.peer_credential');

        $peer = DB::table('node_access_peers')->where('device_id', DB::table('member_devices')->value('id'))->first();
        $job = DB::table('node_access_provisioning_outbox')->where('peer_id', $peer->id)->first();
        $profile = '{"scope":"isolated-test-only","connectable":false}';
        $driver->shouldReceive('provision')->once()->andReturn([
            'node_id' => 'node-test-1', 'peer_id' => $peer->id, 'request_id' => $job->id,
            'generation' => (int) $job->generation, 'acknowledgement_id' => 'test-profile-ack',
            'profile' => $profile, 'confirmation_status' => 'confirmed_test',
        ]);

        $this->assertSame(1, app(NodePeerProvisioningProcessor::class)->processDue());
        $peer = DB::table('node_access_peers')->where('id', $peer->id)->first();
        $this->assertNotSame('stored-device-secret', $peer->credential_ciphertext);
        $this->assertNotSame($profile, $peer->profile_ciphertext);
        $this->assertNotEmpty(Crypt::decryptString($peer->credential_ciphertext));
        $this->assertSame($profile, Crypt::decryptString($peer->profile_ciphertext));
        $this->getJson('/api/v1/me/devices')
            ->assertJsonMissingPath('data.devices.0.profile')
            ->assertJsonMissingPath('data.devices.0.peer_credential');

        $challenge = $this->createBindingChallenge($keyPair);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $this->signChallenge($challenge['challenge'], $keyPair['private_key']),
        ])->assertOk()
            ->assertJsonPath('data.node_authorization.status', 'unavailable')
            ->assertJsonMissingPath('data.node_authorization.peer_credential')
            ->assertJsonMissingPath('data.node_authorization.profile')
            ->assertHeader('Cache-Control', 'no-store, private');

        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'device_key_fingerprint' => str_repeat('f', 64),
        ]);
        $mismatchedPeerChallenge = $this->createBindingChallenge($keyPair);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $mismatchedPeerChallenge['challenge_id'],
            'signature' => $this->signChallenge($mismatchedPeerChallenge['challenge'], $keyPair['private_key']),
        ])->assertOk()
            ->assertJsonPath('data.node_authorization.status', 'unavailable')
            ->assertJsonMissingPath('data.node_authorization.peer_credential')
            ->assertJsonMissingPath('data.node_authorization.profile');

        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'device_key_fingerprint' => hash('sha256', $keyPair['public_key_der']),
        ]);
        DB::table('member_devices')->where('id', $peer->device_id)->update(['binding_generation' => 2]);
        $staleGenerationChallenge = $this->createBindingChallenge($keyPair);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $staleGenerationChallenge['challenge_id'],
            'signature' => $this->signChallenge($staleGenerationChallenge['challenge'], $keyPair['private_key']),
        ])->assertOk()
            ->assertJsonPath('data.node_authorization.status', 'unavailable')
            ->assertJsonMissingPath('data.node_authorization.peer_credential')
            ->assertJsonMissingPath('data.node_authorization.profile');

        DB::table('member_devices')->where('id', $peer->device_id)->update(['binding_generation' => 1]);
        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'status' => 'revocation_pending',
        ]);
        $revokingPeerChallenge = $this->createBindingChallenge($keyPair);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $revokingPeerChallenge['challenge_id'],
            'signature' => $this->signChallenge($revokingPeerChallenge['challenge'], $keyPair['private_key']),
        ])->assertOk()
            ->assertJsonPath('data.node_authorization.status', 'unavailable')
            ->assertJsonMissingPath('data.node_authorization.peer_credential')
            ->assertJsonMissingPath('data.node_authorization.profile');

        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'status' => 'active', 'entitlement_id' => null,
        ]);
        $unboundEntitlementAuthorization = app(NodePeerProvisioningService::class)->ensureForProvenDevice(
            'user-member',
            $peer->device_id,
            hash('sha256', $keyPair['public_key_der']),
        );
        $this->assertSame('unavailable', $unboundEntitlementAuthorization['status']);
        $this->assertArrayNotHasKey('peer_credential', $unboundEntitlementAuthorization);

        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'entitlement_id' => $peer->entitlement_id,
            'expires_at' => now()->subMinute()->utc()->toIso8601String(),
        ]);
        $expiredAuthorization = app(NodePeerProvisioningService::class)->ensureForProvenDevice(
            'user-member',
            $peer->device_id,
            hash('sha256', $keyPair['public_key_der']),
        );
        $this->assertSame('unavailable', $expiredAuthorization['status']);
        $this->assertArrayNotHasKey('peer_credential', $expiredAuthorization);
        $this->assertArrayNotHasKey('profile', $expiredAuthorization);

        DB::table('node_access_peers')->where('id', $peer->id)->update([
            'entitlement_id' => $peer->entitlement_id,
            'expires_at' => now()->addHour()->utc()->toIso8601String(),
            'credential_ciphertext' => 'invalid-ciphertext',
        ]);
        $unreadableCredentialAuthorization = app(NodePeerProvisioningService::class)->ensureForProvenDevice(
            'user-member',
            $peer->device_id,
            hash('sha256', $keyPair['public_key_der']),
        );
        $this->assertSame('unavailable', $unreadableCredentialAuthorization['status']);
        $this->assertArrayNotHasKey('peer_credential', $unreadableCredentialAuthorization);
        $this->assertArrayNotHasKey('profile', $unreadableCredentialAuthorization);
    }

    public function test_device_binding_requires_a_member_session_and_scopes_challenges_to_its_owner(): void
    {
        $keyPair = $this->deviceKeyPair();
        $this->postJson('/api/v1/me/device-bindings/challenge', $this->bindingPayload($keyPair))
            ->assertUnauthorized();
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'signature' => 'abc',
        ])->assertUnauthorized();

        $this->actingAs(User::findOrFail('user-member'));
        $challenge = $this->createBindingChallenge($keyPair);
        $otherMember = User::factory()->create(['id' => 'user-other-member', 'role' => 'member']);
        $this->actingAs($otherMember);
        $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $this->signChallenge($challenge['challenge'], $keyPair['private_key']),
        ])->assertStatus(409)->assertJsonPath('error.code', 'device_binding_challenge_invalid');
        $this->assertDatabaseCount('member_devices', 0);
    }

    public function test_removing_a_device_is_audited_and_installation_id_cannot_be_reused(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $installationId = '778f705a-c8cb-46cf-8c63-cb02aad93c3e';
        $deviceId = $this->postJson('/api/v1/me/devices', [
            'installation_id' => $installationId,
            'name' => 'MacBook',
            'platform' => 'macos',
        ])->assertCreated()->json('data.id');

        $this->deleteJson('/api/v1/me/devices/'.$deviceId)->assertNoContent();
        $this->assertDatabaseHas('member_devices', ['id' => $deviceId, 'status' => 'revoked']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => 'user-member',
            'action' => 'member_device.revoked',
            'target_id' => $deviceId,
        ]);

        $this->postJson('/api/v1/me/devices', [
            'installation_id' => $installationId,
            'name' => 'MacBook',
            'platform' => 'macos',
        ])->assertStatus(409)->assertJsonPath('error.code', 'device_registration_revoked');

        $this->getJson('/api/v1/me/devices')->assertJsonPath('data.registered_count', 0);
        $this->assertDatabaseCount('member_devices', 1);
    }

    public function test_device_removal_is_blocked_while_peer_revocation_is_unconfirmed(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->postJson('/api/v1/me/devices', [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'name' => 'Android 手机',
            'platform' => 'android',
        ])->assertCreated()->json('data.id');

        DB::table('node_access_peers')->insert([
            'id' => 'peer-member-device-1',
            'user_id' => 'user-member',
            'node_id' => 'node-test-1',
            'device_id' => $deviceId,
            'device_name' => 'Android 手机',
            'public_key' => 'public-peer-member-device-1',
            'device_key_fingerprint' => str_repeat('b', 64),
            'generation' => 1,
            'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => encrypt('member-device-credential'),
            'profile_ciphertext' => null,
            'provision_request_id' => 'provision-member-device-1',
            'status' => 'revocation_pending',
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'last_observed_at' => null,
            'revoked_at' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->deleteJson('/api/v1/me/devices/'.$deviceId)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'revocation_pending');

        $this->assertDatabaseHas('member_devices', [
            'id' => $deviceId, 'status' => 'registered',
            'remove_requested_at' => now()->utc()->toIso8601String(),
        ]);
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'peer_id' => 'peer-member-device-1', 'status' => 'pending', 'reason' => 'device_removed',
        ]);
        $this->getJson('/api/v1/me/devices')->assertJsonPath('data.registered_count', 1)
            ->assertJsonPath('data.devices.0.removal_pending', true);
        DB::table('plans')->where('id', 'annual')->update(['max_devices' => 1]);
        $this->postJson('/api/v1/me/devices', [
            'installation_id' => '7be684d8-b65a-4298-9060-25f85cf8ae19',
            'name' => 'Replacement device',
            'platform' => 'windows',
        ])->assertStatus(409)->assertJsonPath('error.code', 'device_limit_reached');

        $job = DB::table('node_access_revocation_outbox')->where('peer_id', 'peer-member-device-1')->first();
        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $driver->shouldReceive('revoke')->once()->andReturn([
            'node_id' => 'node-test-1', 'peer_id' => 'peer-member-device-1', 'request_id' => $job->id,
            'generation' => (int) $job->generation, 'acknowledgement_id' => 'device-removal-ack',
            'upload_bytes' => 0, 'download_bytes' => 0, 'confirmation_status' => 'confirmed_test',
        ]);
        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('member_devices', ['id' => $deviceId, 'status' => 'revoked']);
        $this->getJson('/api/v1/me/devices')->assertJsonPath('data.registered_count', 0);
    }

    public function test_device_records_are_scoped_to_the_authenticated_member(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $deviceId = $this->postJson('/api/v1/me/devices', [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'name' => 'Android 手机',
            'platform' => 'android',
        ])->assertCreated()->json('data.id');

        $otherMember = User::factory()->create([
            'id' => 'user-other-member',
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($otherMember);
        $this->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonCount(0, 'data.devices');
        $this->deleteJson('/api/v1/me/devices/'.$deviceId)->assertNotFound();
    }

    public function test_guests_cannot_access_device_management(): void
    {
        $this->getJson('/api/v1/me/devices')->assertUnauthorized();
        $this->postJson('/api/v1/me/devices', [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'name' => 'Android 手机',
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_registered_client_can_report_version_and_last_seen_without_claiming_a_vpn_connection(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $installationId = '778f705a-c8cb-46cf-8c63-cb02aad93c3e';
        $deviceId = $this->postJson('/api/v1/me/devices', [
            'installation_id' => $installationId,
            'name' => 'Android 手机',
            'platform' => 'android',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/client/heartbeat', [
            'installation_id' => $installationId,
            'client_version' => '1.2.3',
        ])->assertNoContent();

        $this->assertDatabaseHas('member_devices', [
            'id' => $deviceId,
            'client_version' => '1.2.3',
        ]);
        $this->assertNotNull(DB::table('member_devices')->where('id', $deviceId)->value('last_seen_at'));
        $this->getJson('/api/v1/me/devices')
            ->assertJsonMissingPath('data.devices.0.client_version')
            ->assertJsonMissingPath('data.devices.0.last_seen_at');
    }

    public function test_heartbeat_is_scoped_to_registered_devices_and_rejects_revoked_devices(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $installationId = '778f705a-c8cb-46cf-8c63-cb02aad93c3e';
        $deviceId = $this->postJson('/api/v1/me/devices', [
            'installation_id' => $installationId,
            'name' => 'Android 手机',
            'platform' => 'android',
        ])->assertCreated()->json('data.id');
        $otherMember = User::factory()->create([
            'id' => 'user-other-member',
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($otherMember);
        $this->postJson('/api/v1/client/heartbeat', [
            'installation_id' => $installationId,
            'client_version' => '1.2.3',
        ])->assertNotFound();

        $this->actingAs(User::findOrFail('user-member'));
        $this->deleteJson('/api/v1/me/devices/'.$deviceId)->assertNoContent();
        $this->postJson('/api/v1/client/heartbeat', [
            'installation_id' => $installationId,
            'client_version' => '1.2.4',
        ])->assertNotFound();
    }

    public function test_heartbeat_requires_session_and_a_valid_client_version(): void
    {
        $payload = [
            'installation_id' => '778f705a-c8cb-46cf-8c63-cb02aad93c3e',
            'client_version' => '1.2.3',
        ];

        $this->postJson('/api/v1/client/heartbeat', $payload)->assertUnauthorized();

        $this->actingAs(User::findOrFail('user-member'));
        $this->postJson('/api/v1/client/heartbeat', [...$payload, 'client_version' => '   '])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_error');
    }

    /** @return array{private_key: OpenSSLAsymmetricKey, public_key: string, public_key_der: string} */
    private function deviceKeyPair(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($privateKey);
        $this->assertTrue(openssl_pkey_export($privateKey, $privateKeyPem));
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $publicKeyDer = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\\s+/', '', $details['key']), true);
        $this->assertNotFalse($publicKeyDer);

        return [
            'private_key' => $privateKey,
            'public_key' => $this->base64UrlEncode($publicKeyDer),
            'public_key_der' => $publicKeyDer,
        ];
    }

    private function prepareProductionRoute(): void
    {
        $now = now()->utc()->toIso8601String();
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'is_test' => false, 'health_status' => 'healthy', 'last_health_check_at' => $now,
            'agent_endpoint' => 'https://node-agent.example.invalid',
        ]);
        DB::table('subscription_route_versions')->insert([
            'id' => 'route-profile-test', 'scope' => 'production', 'version' => 1,
            'status' => 'published', 'canary_percent' => 100, 'actor_user_id' => 'user-admin',
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('subscription_route_nodes')->insert([
            'id' => 'route-profile-test-node', 'route_version_id' => 'route-profile-test',
            'node_id' => 'node-test-1', 'weight' => 100, 'created_at' => $now,
        ]);
    }

    /** @param array{private_key: OpenSSLAsymmetricKey, public_key: string, public_key_der: string} $keyPair */
    private function authorizeDevice(array $keyPair, string $installationId = '778f705a-c8cb-46cf-8c63-cb02aad93c3e')
    {
        $challenge = $this->createBindingChallenge($keyPair, $installationId);
        $signature = $this->signChallenge($challenge['challenge'], $keyPair['private_key']);

        return $this->postJson('/api/v1/me/device-bindings/authorize', [
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $signature,
        ]);
    }

    /** @param array{private_key: OpenSSLAsymmetricKey, public_key: string, public_key_der: string} $keyPair
     * @return array{challenge_id:string,challenge:string}
     */
    private function createBindingChallenge(array $keyPair, string $installationId = '778f705a-c8cb-46cf-8c63-cb02aad93c3e'): array
    {
        return $this->postJson('/api/v1/me/device-bindings/challenge', $this->bindingPayload($keyPair, $installationId))
            ->assertOk()
            ->json('data');
    }

    /** @param array{private_key: OpenSSLAsymmetricKey, public_key: string, public_key_der: string} $keyPair
     * @return array{installation_id:string,name:string,platform:string,public_key:string}
     */
    private function bindingPayload(array $keyPair, string $installationId = '778f705a-c8cb-46cf-8c63-cb02aad93c3e'): array
    {
        return [
            'installation_id' => $installationId,
            'name' => 'Android test device',
            'platform' => 'android',
            'public_key' => $keyPair['public_key'],
        ];
    }

    private function signChallenge(string $challenge, OpenSSLAsymmetricKey $privateKey): string
    {
        $challengeBytes = $this->base64UrlDecode($challenge);
        $this->assertNotFalse(openssl_sign($challengeBytes, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        return base64_decode(strtr($value.str_repeat('=', $padding === 0 ? 0 : 4 - $padding), '-_', '+/'), true);
    }
}
