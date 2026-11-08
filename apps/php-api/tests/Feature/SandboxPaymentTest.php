<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SandboxPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::findOrFail('user-member'));
    }

    public function test_local_sandbox_payment_creates_one_payment_and_appends_entitlement_after_current_term(): void
    {
        $orderId = $this->createOrder();
        $currentEnd = DB::table('entitlements')->where('id', 'entitlement-member-annual')->value('ends_at');

        $response = $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid_test')
            ->assertJsonPath('data.payment.status', 'accepted_test')
            ->assertJsonPath('data.entitlement_grant.status', 'granted_test');
        $subscription = $response->json('data.subscription');
        $this->assertNotEmpty($subscription['url']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertNotNull(DB::table('subscriptions')->where('id', $subscription['id'])->value('token_encrypted'));
        $paymentId = $response->json('data.payment.id');
        $entitlementId = $response->json('data.entitlement_grant.entitlement_id');
        $grant = DB::table('entitlements')->where('id', $entitlementId)->first();

        $this->assertSame($currentEnd, $grant->starts_at);
        $this->assertSame(1, DB::table('entitlement_grants')->where('order_id', $orderId)->count());
        $this->assertSame(1, DB::table('sandbox_payment_events')->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('sandbox_payment_events', ['id' => $paymentId, 'amount_cents' => 2980, 'currency' => 'CNY', 'status' => 'accepted_test']);
        $this->assertDatabaseHas('order_events', ['order_id' => $orderId, 'from_status' => 'pending_test', 'to_status' => 'paid_test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.sandbox_paid', 'target_id' => $orderId]);
        $this->assertDatabaseCount('entitlements', 2);
        $this->assertDatabaseCount('commission_entries', 1);

        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertOk()
            ->assertJsonPath('data.payment.id', $paymentId)
            ->assertJsonPath('data.entitlement_grant.entitlement_id', $entitlementId)
            ->assertJsonPath('data.subscription.url', $subscription['url']);
        $this->getJson('/api/v1/me/subscriptions')->assertOk()
            ->assertJsonPath('data.0.id', $subscription['id'])
            ->assertJsonPath('data.0.url', $subscription['url']);
        $this->getJson('/api/v1/me/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.payment.id', $paymentId)
            ->assertJsonPath('data.entitlement_grant.entitlement_id', $entitlementId);
        $this->getJson('/api/v1/me/overview')->assertJsonPath('data.entitlement.plan_name', '年会员');
        $this->assertSame($currentEnd, DB::table('entitlements')->where('user_id', 'user-member')->where('starts_at', '<=', now()->toIso8601String())->value('ends_at'));
        $this->assertDatabaseCount('entitlement_grants', 1);
        $this->assertDatabaseCount('sandbox_payment_events', 1);
        $this->assertDatabaseCount('order_events', 2);
    }

    public function test_confirmed_renewal_reuses_the_existing_subscription_link(): void
    {
        $existing = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data');
        $orderId = $this->createOrder();

        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertCreated()
            ->assertJsonPath('data.subscription.id', $existing['id'])
            ->assertJsonPath('data.subscription.url', $existing['url']);

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_expired_sandbox_payment_is_recorded_for_review_without_granting_entitlement(): void
    {
        $orderId = $this->createOrder();
        $this->travel(31)->minutes();

        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertStatus(409)
            ->assertJsonPath('error.code', 'payment_requires_review');
        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertStatus(409);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending_test']);
        $this->assertDatabaseHas('sandbox_payment_events', ['order_id' => $orderId, 'status' => 'review_test']);
        $this->assertDatabaseCount('sandbox_payment_events', 1);
        $this->assertDatabaseCount('entitlement_grants', 0);
        $this->assertDatabaseCount('entitlements', 1);
        $this->assertDatabaseCount('commission_entries', 1);
    }

    public function test_only_the_order_owner_can_simulate_payment_and_paid_sample_is_immutable(): void
    {
        $orderId = $this->createOrder();
        User::factory()->create(['id' => 'other-member', 'role' => 'member']);
        $this->actingAs(User::findOrFail('other-member'));
        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertNotFound();

        $this->actingAs(User::findOrFail('user-member'));
        $this->postJson('/api/v1/me/orders/order-demo-001/sandbox-payment')->assertStatus(409);
        $this->assertDatabaseCount('sandbox_payment_events', 0);
        $this->assertDatabaseCount('entitlement_grants', 0);

        $this->actingAs(User::findOrFail('user-admin'));
        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertForbidden();
        $this->getJson('/api/v1/admin/orders/'.$orderId)->assertOk()->assertJsonPath('data.payment', null);
    }

    public function test_sandbox_payment_is_disabled_outside_local_and_testing_environments(): void
    {
        $orderId = $this->createOrder();
        $this->withSession(['_token' => 'sandbox-test-csrf'])->withHeader('X-CSRF-TOKEN', 'sandbox-test-csrf');
        $this->app->instance('env', 'production');

        $this->postJson('/api/v1/me/orders/'.$orderId.'/sandbox-payment')->assertForbidden();
        $this->assertDatabaseCount('sandbox_payment_events', 0);
        $this->assertDatabaseCount('entitlement_grants', 0);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending_test']);
    }

    private function createOrder(): string
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])
            ->assertCreated()
            ->json('data.id');
    }
}
