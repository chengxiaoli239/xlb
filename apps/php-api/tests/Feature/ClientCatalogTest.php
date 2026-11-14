<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_member_catalog_is_authenticated_and_excludes_unpublished_items(): void
    {
        $this->getJson('/api/v1/me/clients')->assertUnauthorized();

        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/me/clients')->assertOk()->assertJsonCount(12, 'data')->assertJsonFragment([
            'id' => 'client-android-clash-mi',
            'compatibility_status' => 'pending',
            'download_url' => null,
        ]);
        $this->getJson('/api/v1/admin/clients')->assertForbidden();

        DB::table('client_catalog')->where('id', 'client-android-clash-mi')->update(['is_active' => false]);
        $this->getJson('/api/v1/me/clients')->assertJsonCount(11, 'data')->assertJsonMissing(['id' => 'client-android-clash-mi']);
    }

    public function test_admin_can_create_update_and_unpublish_audited_client_items(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $this->postJson('/api/v1/admin/clients', [])->assertForbidden();

        $this->actingAs(User::findOrFail('user-admin'));
        $this->postJson('/api/v1/admin/clients', $this->validItem(['download_url' => 'http://downloads.example.test/app.apk']))->assertStatus(400);
        $created = $this->postJson('/api/v1/admin/clients', $this->validItem())->assertCreated()->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.created', 'target_id' => $created]);
        $this->assertDatabaseHas('client_catalog', ['id' => $created, 'compatibility_status' => 'pending', 'is_active' => true]);

        $this->putJson('/api/v1/admin/clients/'.$created, $this->validItem([
            'name' => 'Clash Mi Verified',
            'version' => '2.4.0',
            'download_url' => 'https://downloads.example.test/app.apk',
            'compatibility_status' => 'pending',
        ]))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.updated', 'target_id' => $created]);
        $this->assertDatabaseHas('client_catalog', ['id' => $created, 'version' => '2.4.0', 'compatibility_status' => 'pending']);

        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/me/clients')->assertJsonFragment(['id' => $created, 'download_url' => 'https://downloads.example.test/app.apk']);

        $this->actingAs(User::findOrFail('user-admin'));
        $this->patchJson('/api/v1/admin/clients/'.$created.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.unpublished', 'target_id' => $created]);
        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/me/clients')->assertJsonMissing(['id' => $created]);
    }

    public function test_admin_client_validation_rejects_unknown_status_and_invalid_platform(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $this->postJson('/api/v1/admin/clients', $this->validItem(['platform' => 'server']))->assertStatus(400);
        $this->postJson('/api/v1/admin/clients', $this->validItem(['compatibility_status' => 'live']))->assertStatus(400);
        $this->postJson('/api/v1/admin/clients', $this->validItem(['compatibility_status' => 'verified']))->assertStatus(400);
        $this->patchJson('/api/v1/admin/clients/client-android-clash-mi/status', ['is_active' => 'sometimes'])->assertStatus(400);
    }

    public function test_admin_must_record_current_version_evidence_before_marking_client_verified(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $clientId = $this->postJson('/api/v1/admin/clients', $this->validItem(['version' => '2.4.0']))
            ->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertStatus(409)->assertJsonPath('error.code', 'verification_evidence_required');

        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $this->validCompatibilityRun())
            ->assertCreated()->assertJsonPath('data.result', 'passed')->assertJsonPath('data.matches_current_version', true);
        $this->assertDatabaseHas('client_catalog', ['id' => $clientId, 'compatibility_status' => 'pending']);
        $this->getJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.core_version', '1.19.33');
        $this->putJson('/api/v1/admin/clients/'.$clientId, $this->validItem([
            'name' => 'Clash Mi Test Renamed', 'version' => '2.4.0',
        ]))->assertOk();

        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertOk()->assertJsonPath('data.compatibility_status', 'verified');
        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertOk()->assertJsonPath('data.compatibility_status', 'verified');
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.compatibility_verified', 'target_id' => $clientId]);
    }

    public function test_failed_or_stale_compatibility_runs_cannot_verify_a_client(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $clientId = $this->postJson('/api/v1/admin/clients', $this->validItem(['version' => '2.4.0']))
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $this->validCompatibilityRun([
            'client_version' => '2.3.9',
        ]))->assertCreated()->assertJsonPath('data.result', 'stale');
        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertStatus(409);

        $failedChecks = $this->validCompatibilityRun()['checks'];
        $failedChecks['tcp_connect'] = false;
        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $this->validCompatibilityRun([
            'checks' => $failedChecks,
        ]))->assertCreated()->assertJsonPath('data.result', 'failed');
        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertStatus(409)->assertJsonPath('error.code', 'verification_evidence_required');
        $this->assertDatabaseHas('client_catalog', ['id' => $clientId, 'compatibility_status' => 'pending']);
    }

    public function test_udp_supported_run_requires_a_passing_udp_check(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $clientId = $this->postJson('/api/v1/admin/clients', $this->validItem(['version' => '2.4.0']))
            ->assertCreated()->json('data.id');

        $run = $this->validCompatibilityRun();
        $run['udp_supported'] = true;
        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $run)->assertStatus(400);

        $run['checks']['udp'] = false;
        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $run)
            ->assertCreated()->assertJsonPath('data.result', 'failed');
    }

    public function test_new_failed_run_demotes_current_verified_client_and_version_change_resets_status(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $clientId = $this->postJson('/api/v1/admin/clients', $this->validItem(['version' => '2.4.0']))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $this->validCompatibilityRun())->assertCreated();
        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])->assertOk();

        $failedChecks = $this->validCompatibilityRun()['checks'];
        $failedChecks['dns'] = false;
        $this->postJson('/api/v1/admin/clients/'.$clientId.'/compatibility-runs', $this->validCompatibilityRun([
            'checks' => $failedChecks,
        ]))->assertCreated()->assertJsonPath('data.result', 'failed');
        $this->assertDatabaseHas('client_catalog', ['id' => $clientId, 'compatibility_status' => 'maintenance']);
        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertStatus(409)->assertJsonPath('error.code', 'verification_evidence_required');

        $this->putJson('/api/v1/admin/clients/'.$clientId, $this->validItem([
            'name' => 'Clash Mi Test 2', 'version' => '2.5.0', 'compatibility_status' => 'maintenance',
        ]))->assertOk();
        $this->assertDatabaseHas('client_catalog', ['id' => $clientId, 'version' => '2.5.0', 'compatibility_status' => 'pending']);
        $this->patchJson('/api/v1/admin/clients/'.$clientId.'/compatibility-status', ['status' => 'verified'])
            ->assertStatus(409)->assertJsonPath('error.code', 'verification_evidence_required');
    }

    public function test_members_cannot_read_or_submit_compatibility_evidence(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/admin/clients/client-android-clash-mi/compatibility-runs')->assertForbidden();
        $this->postJson('/api/v1/admin/clients/client-android-clash-mi/compatibility-runs', $this->validCompatibilityRun())->assertForbidden();
        $this->patchJson('/api/v1/admin/clients/client-android-clash-mi/compatibility-status', ['status' => 'verified'])->assertForbidden();
    }

    /** @return array<string, bool|int|string|null> */
    private function validItem(array $overrides = []): array
    {
        return array_merge([
            'platform' => 'android',
            'name' => 'Clash Mi Test',
            'version' => null,
            'description' => '测试客户端目录项',
            'download_url' => null,
            'qr_code_url' => null,
            'tutorial_url' => null,
            'compatibility_status' => 'pending',
            'sort_order' => 100,
            'is_active' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function validCompatibilityRun(array $overrides = []): array
    {
        return array_merge([
            'client_version' => '2.4.0',
            'core_name' => 'Mihomo Meta',
            'core_version' => '1.19.33',
            'os_version' => 'Windows 11 24H2',
            'profile_format' => 'clash',
            'network_type' => 'wifi',
            'udp_supported' => false,
            'checks' => [
                'profile_parse' => true,
                'subscription_import' => true,
                'subscription_refresh' => true,
                'mode_switch' => true,
                'tcp_connect' => true,
                'disconnect' => true,
                'expiry_revoke' => true,
                'dns' => true,
            ],
            'evidence_reference' => 'QA-2026-001',
            'tested_at' => now()->toIso8601String(),
            'notes' => null,
        ], $overrides);
    }
}
