<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SandboxRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_refund_revokes_the_order_entitlement_and_invalidates_the_subscription(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();
        $subscriptionUrl = $this->postJson('/api/v1/me/subscriptions')->assertOk()->json('data.url');
        $subscriptionId = DB::table('subscriptions')->where('user_id', $member->id)->where('status', 'active')->value('id');
        $grant = DB::table('entitlement_grants')->where('order_id', $orderId)->first();
        DB::table('commission_entries')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'partner_name' => '测试代理',
            'amount_cents' => 300, 'status' => 'pending', 'created_at' => now()->toIso8601String(),
        ]);
        $refundKey = (string) Str::uuid();

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', $refundKey)
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '客户申请全额退款'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'refunded_test')
            ->assertJsonPath('data.refund.status', 'refunded_test')
            ->assertJsonPath('data.refund.amount_cents', 2980)
            ->assertJsonPath('data.entitlement_grant.status', 'revoked_test');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'refunded_test']);
        $this->assertDatabaseHas('entitlements', ['id' => $grant->entitlement_id, 'status' => 'revoked_test']);
        $this->assertDatabaseHas('entitlement_grants', ['order_id' => $orderId, 'status' => 'revoked_test']);
        $this->assertDatabaseHas('sandbox_payment_events', ['order_id' => $orderId, 'status' => 'accepted_test']);
        $this->assertDatabaseHas('sandbox_refund_events', ['order_id' => $orderId, 'status' => 'refunded_test', 'idempotency_key' => $refundKey]);
        $this->assertDatabaseHas('order_events', ['order_id' => $orderId, 'from_status' => 'paid_test', 'to_status' => 'refunded_test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.sandbox_refunded', 'target_id' => $orderId]);
        $this->assertDatabaseHas('commission_entries', ['order_id' => $orderId, 'status' => 'reversed_test']);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'revoked', 'token_encrypted' => null]);
        $this->get($subscriptionUrl)->assertNotFound();
    }

    public function test_refund_is_idempotent_and_does_not_create_a_second_ledger_entry(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();
        $refundKey = (string) Str::uuid();

        $this->actingAs(User::findOrFail('user-admin'))->withHeader('Idempotency-Key', $refundKey)
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '重复请求检查'])
            ->assertCreated();
        $this->withHeader('Idempotency-Key', $refundKey)
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '重复请求检查'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded_test');

        $this->assertDatabaseCount('sandbox_refund_events', 1);
        $this->assertDatabaseCount('order_events', 3);
        $this->assertDatabaseCount('audit_logs', 5);
    }

    public function test_member_cannot_refund_and_unpaid_orders_are_rejected(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '无权操作'])
            ->assertForbidden();

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/order-demo-001/sandbox-refund', ['reason' => '没有对应沙箱支付'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_transition');

        $this->assertDatabaseCount('sandbox_refund_events', 0);
        $this->assertDatabaseHas('orders', ['id' => 'order-demo-001', 'status' => 'paid_test']);
    }

    public function test_refunding_one_order_preserves_other_valid_entitlements(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $orderId = $this->createPaidOrder();
        $grant = DB::table('entitlement_grants')->where('order_id', $orderId)->first();
        $subscriptionUrl = $this->postJson('/api/v1/me/subscriptions')->assertOk()->json('data.url');

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '撤销该笔续费权益'])
            ->assertCreated();

        $this->assertDatabaseHas('entitlements', ['id' => 'entitlement-member-annual', 'status' => 'active']);
        $this->assertDatabaseHas('entitlements', ['id' => $grant->entitlement_id, 'status' => 'revoked_test']);
        $this->get($subscriptionUrl)->assertOk();
    }

    public function test_sandbox_refund_is_disabled_outside_local_and_testing_environments(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();
        $this->actingAs(User::findOrFail('user-admin'));
        $this->withSession(['_token' => 'sandbox-refund-test-csrf'])->withHeader('X-CSRF-TOKEN', 'sandbox-refund-test-csrf');
        $this->app->instance('env', 'production');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '生产环境禁止沙箱退款'])
            ->assertForbidden();
        $this->assertDatabaseCount('sandbox_refund_events', 0);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'paid_test']);
    }

    private function createPaidOrder(): string
    {
        $orderId = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])
            ->assertCreated()
            ->json('data.id');

        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertCreated();

        return $orderId;
    }
}
