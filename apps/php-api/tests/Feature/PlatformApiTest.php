<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_and_role_isolation(): void
    {
        $this->getJson('/api/v1/auth/csrf')->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->postJson('/api/v1/auth/login', ['email' => 'member@xlb.local', 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'member@xlb.local', 'password' => 'member-local-2026'])->assertOk()->assertJsonPath('data.role', 'member');
        $this->getJson('/api/v1/admin/plans')->assertForbidden();
        $this->getJson('/api/v1/me/overview')->assertOk()->assertJsonPath('data.entitlement.plan_name', '年会员');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/me/overview')->assertUnauthorized();
    }

    public function test_admin_can_version_and_unpublish_plans_without_changing_historical_rights(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $this->getJson('/api/v1/catalog/plans')->assertOk()->assertJsonCount(4, 'data');
        $changed = $this->putJson('/api/v1/admin/plans/annual', [
            'name' => '年会员新版', 'period_label' => '1 年', 'duration_days' => 365,
            'price_cents' => 31880, 'traffic_gb' => 1800, 'max_devices' => 6,
        ])->assertOk()->assertJsonPath('data.version', 2)->json('data');
        $this->assertNotSame('annual', $changed['id']);
        $this->assertSame('annual', DB::table('orders')->where('id', 'order-demo-001')->value('plan_id'));
        $this->assertSame('annual', DB::table('entitlements')->where('id', 'entitlement-member-annual')->value('plan_id'));
        $this->getJson('/api/v1/catalog/plans')->assertJsonFragment(['name' => '年会员新版']);
        $this->patchJson('/api/v1/admin/plans/annual/status', ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/api/v1/catalog/plans')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/admin/plans')->assertJsonCount(4, 'data');

        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/me/overview')->assertJsonPath('data.entitlement.plan_name', '年会员')->assertJsonPath('data.entitlement.price_cents', 27880);
        $this->getJson('/api/v1/me/orders')->assertJsonPath('data.0.plan_name', '年会员');
    }

    public function test_admin_can_create_plan_and_reject_invalid_values(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $this->postJson('/api/v1/admin/plans', ['name' => '测试套餐', 'period_label' => '1 个月', 'duration_days' => 30, 'price_cents' => -1, 'traffic_gb' => 100, 'max_devices' => 2])->assertStatus(400);
        $this->postJson('/api/v1/admin/plans', ['name' => '测试套餐', 'period_label' => '1 个月', 'duration_days' => 30, 'price_cents' => 1990, 'traffic_gb' => 100, 'max_devices' => 2])->assertCreated()->assertJsonPath('data.version', 1);
        $this->getJson('/api/v1/catalog/plans')->assertJsonCount(5, 'data');
    }

    public function test_subscription_can_be_rotated_and_revoked(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $created = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data');
        $this->assertSame(hash('sha256', basename($created['url'])), DB::table('subscriptions')->where('id', $created['id'])->value('token_hash'));
        $this->assertNotSame(basename($created['url']), DB::table('subscriptions')->where('id', $created['id'])->value('token_encrypted'));
        $this->getJson('/api/v1/me/subscriptions')->assertOk()->assertJsonPath('data.0.url', $created['url']);
        $this->postJson('/api/v1/me/subscriptions')->assertOk()->assertJsonPath('data.url', $created['url']);
        $this->get($created['url'])->assertOk()->assertSee('type: socks5')->assertSee('198.51.100.10');
        $rotated = $this->postJson('/api/v1/me/subscriptions/'.$created['id'].'/rotate')->assertOk()->json('data');
        $this->assertSame($created['id'], $rotated['id']);
        $this->get($created['url'])->assertNotFound();
        $this->get($rotated['url'])->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->postJson('/api/v1/me/subscriptions/'.$rotated['id'].'/revoke')->assertNoContent();
        $this->get($rotated['url'])->assertNotFound();
    }

    public function test_subscription_can_be_created_before_membership_and_used_after_it_starts(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        DB::table('entitlements')->where('user_id', 'user-member')->delete();

        $created = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data');
        $this->assertNull($created['expires_at']);
        $this->assertSame($created['url'], $this->getJson('/api/v1/me/subscriptions')->assertOk()->json('data.0.url'));
        $this->get($created['url'])->assertNotFound();

        DB::table('entitlements')->insert([
            'id' => 'entitlement-member-annual',
            'user_id' => 'user-member',
            'plan_id' => 'annual',
            'status' => 'active',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
            'data_used_gb' => 0,
        ]);

        $this->get($created['url'])->assertOk();
    }

    public function test_future_membership_is_not_current_or_usable_for_subscription_profiles(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        DB::table('entitlements')->where('user_id', 'user-member')->update([
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(),
        ]);

        $this->getJson('/api/v1/me/overview')->assertOk()->assertJsonPath('data.entitlement', null);
        $created = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data');
        $this->get($created['url'])->assertNotFound();
    }

    public function test_node_catalog_only_creates_local_test_records(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $this->postJson('/api/v1/admin/nodes', ['name' => '演示节点 02', 'region' => '测试区域'])->assertForbidden();

        $this->actingAs(User::findOrFail('user-admin'));
        $this->postJson('/api/v1/admin/nodes', ['name' => '演示节点 02', 'region' => '测试区域', 'endpoint_host' => 'example.com'])->assertStatus(400);
        $this->postJson('/api/v1/admin/nodes', ['name' => '   ', 'region' => '测试区域'])->assertStatus(400);
        $this->postJson('/api/v1/admin/nodes', ['name' => ' a ', 'region' => '测试区域'])->assertStatus(400);
        $created = $this->postJson('/api/v1/admin/nodes', ['name' => '演示节点 02', 'region' => '测试区域'])
            ->assertCreated()->assertJsonPath('data.status', 'candidate')->assertJsonPath('data.health_status', 'not_connected')
            ->assertJsonPath('data.config_version', 1)->assertJsonPath('data.is_test', true)->json('data');

        $this->assertStringEndsWith('.invalid', $created['endpoint_host']);
        $this->assertNull($created['last_health_check_at']);
        $this->getJson('/api/v1/admin/nodes')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/summary')->assertJsonPath('data.nodeCount', 2)->assertJsonPath('data.availableNodes', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.created', 'target_id' => $created['id']]);
        $this->patchJson('/api/v1/admin/nodes/'.$created['id'], ['status' => 'retired'])->assertOk();
        $this->patchJson('/api/v1/admin/nodes/'.$created['id'], ['status' => 'available'])->assertStatus(409);
    }

    public function test_node_lifecycle_rejects_invalid_transitions_and_real_nodes(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'retired'])->assertStatus(409);
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'draining'])
            ->assertOk()->assertJsonPath('data.previous_status', 'available')->assertJsonPath('data.status', 'draining');
        $this->getJson('/api/v1/admin/nodes/node-test-1')->assertOk()
            ->assertJsonPath('data.changes.0.from_status', 'available')->assertJsonPath('data.changes.0.to_status', 'draining');
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'available'])->assertOk();
        $this->getJson('/api/v1/admin/nodes/node-test-1')->assertJsonPath('data.changes.0.from_status', 'draining');
        $this->assertSame(2, DB::table('audit_logs')->where('target_id', 'node-test-1')->count());
        $this->assertSame(2, DB::table('node_state_changes')->where('node_id', 'node-test-1')->count());
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'available'])->assertOk();
        $this->assertSame(2, DB::table('audit_logs')->where('target_id', 'node-test-1')->count());

        DB::table('nodes')->where('id', 'node-test-1')->update(['is_test' => false]);
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'maintenance'])->assertStatus(409);
        DB::table('nodes')->where('id', 'node-test-1')->update(['is_test' => true, 'status' => 'disabled']);
        $this->getJson('/api/v1/admin/nodes/node-test-1')->assertJsonPath('data.allowed_transitions.0', 'maintenance');
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'maintenance'])->assertOk();
    }

    public function test_draining_test_node_removes_it_from_subscription_profile_until_restored(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $url = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data.url');
        $this->get($url)->assertOk();

        $this->actingAs(User::findOrFail('user-admin'));
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'draining'])->assertOk();
        $this->get($url)->assertNotFound();
        $this->patchJson('/api/v1/admin/nodes/node-test-1', ['status' => 'available'])->assertOk();
        $this->get($url)->assertOk();
    }

    public function test_member_and_admin_can_manage_a_private_ticket(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $this->postJson('/api/v1/me/tickets', ['category' => 'connection', 'subject' => '连接一直失败', 'description' => '我的地址 /subscribe/abcdefghijklmnopqrstuvwxyz 无法使用'])->assertStatus(400)->assertJsonPath('error.code', 'credential_not_allowed');
        $id = $this->postJson('/api/v1/me/tickets', ['category' => 'connection', 'subject' => '连接一直失败', 'description' => '客户端提示连接失败，发生在今天上午。'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/me/tickets')->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/v1/admin/tickets/'.$id, ['status' => 'resolved'])->assertForbidden();

        $this->actingAs(User::findOrFail('user-admin'));
        $this->patchJson('/api/v1/admin/tickets/'.$id, ['status' => 'resolved'])->assertOk();
        $this->postJson('/api/v1/admin/tickets/'.$id.'/replies', ['body' => '处理结果补充说明。'])->assertCreated();
        $this->getJson('/api/v1/admin/tickets/'.$id)->assertJsonPath('data.status', 'resolved');

        $this->actingAs(User::findOrFail('user-member'));
        $this->postJson('/api/v1/me/tickets/'.$id.'/replies', ['body' => '这里仍然无法连接。'])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->getJson('/api/v1/me/tickets/'.$id)->assertJsonCount(5, 'data.events');
    }
}
