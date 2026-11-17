<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'status' => 'revocation_pending',
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'last_observed_at' => null,
            'revoked_at' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->deleteJson('/api/v1/me/devices/'.$deviceId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_peer_revocation_pending');

        $this->assertDatabaseHas('member_devices', ['id' => $deviceId, 'status' => 'registered']);
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
}
