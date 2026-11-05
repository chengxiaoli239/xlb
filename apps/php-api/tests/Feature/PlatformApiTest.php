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
        $this->get($created['url'])->assertOk()->assertSee('type: socks5')->assertSee('198.51.100.10');
        $rotated = $this->postJson('/api/v1/me/subscriptions/'.$created['id'].'/rotate')->assertOk()->json('data');
        $this->get($created['url'])->assertNotFound();
        $this->get($rotated['url'])->assertOk();
        $this->postJson('/api/v1/me/subscriptions/'.$rotated['id'].'/revoke')->assertNoContent();
        $this->get($rotated['url'])->assertNotFound();
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
