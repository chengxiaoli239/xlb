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
            'compatibility_status' => 'verified',
        ]))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.updated', 'target_id' => $created]);
        $this->assertDatabaseHas('client_catalog', ['id' => $created, 'version' => '2.4.0', 'compatibility_status' => 'verified']);

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
        $this->patchJson('/api/v1/admin/clients/client-android-clash-mi/status', ['is_active' => 'sometimes'])->assertStatus(400);
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
}
