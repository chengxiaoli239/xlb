<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::findOrFail('user-member'));
    }

    public function test_server_prices_order_and_retries_do_not_duplicate_or_grant_rights(): void
    {
        $key = (string) Str::uuid();
        $id = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.total_cents', 2980)->assertJsonPath('data.status', 'pending_test')
            ->assertJsonPath('data.plan_snapshot.version', 1)->json('data.id');
        $this->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/orders', ['plan_id' => 'monthly', 'total_cents' => 1])->assertStatus(400);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('entitlements', 1);
        $this->assertDatabaseCount('commission_entries', 1);
        $this->assertDatabaseCount('order_events', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.created', 'target_id' => $id]);
    }

    public function test_snapshots_survive_plan_changes_and_retries_but_new_orders_require_current_active_version(): void
    {
        $key = (string) Str::uuid();
        $id = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])->assertCreated()->json('data.id');
        $this->actingAs(User::findOrFail('user-admin'));
        $next = $this->putJson('/api/v1/admin/plans/monthly', ['name' => '新版月会员', 'period_label' => '月', 'duration_days' => 30, 'price_cents' => 3990, 'traffic_gb' => 300, 'max_devices' => 3])->assertOk()->json('data.id');
        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/me/orders/'.$id)->assertJsonPath('data.plan_name', '月会员')->assertJsonPath('data.total_cents', 2980);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])->assertOk()->assertJsonPath('data.id', $id);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])->assertStatus(409);
        DB::table('plans')->where('id', $next)->update(['active' => false]);
        $this->postJson('/api/v1/me/orders', ['plan_id' => $next])->assertStatus(409);
    }

    public function test_private_order_cancellation_is_idempotent_and_paid_records_are_immutable(): void
    {
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled_test');
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertOk();
        $this->assertDatabaseCount('order_events', 2);
        $this->postJson('/api/v1/me/orders/order-demo-001/cancel')->assertStatus(409);
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->actingAs(User::findOrFail('user-admin'));
        $this->getJson('/api/v1/me/orders/'.$id)->assertNotFound();
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertForbidden();
        $this->getJson('/api/v1/admin/orders/'.$id)->assertOk()->assertJsonCount(2, 'data.events');
        $this->getJson('/api/v1/admin/orders?status=cancelled_test&per_page=1')->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
    }

    public function test_expiry_is_effective_on_reads_and_materialized_once_by_command(): void
    {
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertCreated()->json('data.id');
        $this->travel(30)->minutes();
        $this->getJson('/api/v1/me/orders/'.$id)->assertOk()->assertJsonPath('data.status', 'expired_test');
        $this->getJson('/api/v1/me/orders?status=pending_test')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/me/orders?status=expired_test')->assertJsonPath('meta.total', 1);
        $recent = $this->getJson('/api/v1/me/overview')->assertOk()->json('data.recentOrders');
        $this->assertSame('expired_test', collect($recent)->firstWhere('id', $id)['status']);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'pending_test']);
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertStatus(409);
        $this->artisan('orders:expire-test')->assertSuccessful();
        $this->artisan('orders:expire-test')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'expired_test']);
        $this->assertDatabaseCount('order_events', 2);
        $this->assertDatabaseCount('entitlements', 1);
    }

    public function test_member_cannot_read_or_cancel_another_members_order_or_non_test_record(): void
    {
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])->assertCreated()->json('data.id');
        DB::table('orders')->where('id', $id)->update(['is_test' => false]);
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertStatus(409);
        User::factory()->create(['id' => 'other-member', 'role' => 'member']);
        $this->actingAs(User::findOrFail('other-member'));
        $this->getJson('/api/v1/me/orders/'.$id)->assertNotFound();
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertNotFound();
        $this->getJson('/api/v1/me/orders')->assertJsonPath('meta.total', 0);
        $this->travel(31)->minutes();
        $this->artisan('orders:expire-test')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'pending_test']);
    }

    public function test_pagination_and_expiry_preserve_cancelled_and_paid_orders(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertCreated();
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'monthly'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/me/orders/'.$id.'/cancel')->assertOk();
        $this->getJson('/api/v1/me/orders?per_page=1&page=2')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.total_pages', 3)->assertJsonCount(1, 'data');
        $this->travel(31)->minutes();
        $this->artisan('orders:expire-test')->assertSuccessful();
        $this->getJson('/api/v1/me/orders?status=cancelled_test')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/me/orders?status=paid_test')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/me/orders?status=expired_test')->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('entitlements', 1);
        $this->assertDatabaseCount('commission_entries', 1);
    }

    public function test_validation_authentication_and_environment_boundaries(): void
    {
        $this->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertStatus(400);
        $this->withHeader('Idempotency-Key', 'bad')->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertStatus(400);
        $this->getJson('/api/v1/me/orders?page=0')->assertStatus(400);
        $this->getJson('/api/v1/me/orders?status=paid')->assertStatus(400);
        $this->getJson('/api/v1/me/orders/nonexistent')->assertNotFound();
        $this->actingAs(User::findOrFail('user-admin'));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertForbidden();
        $this->actingAs(User::findOrFail('user-member'));
        $this->withSession(['_token' => 'order-test-csrf'])->withHeader('X-CSRF-TOKEN', 'order-test-csrf');
        $this->app->instance('env', 'production');
        $this->postJson('/api/v1/me/orders', ['plan_id' => 'weekly'])->assertForbidden();
        $this->postJson('/api/v1/me/orders/order-demo-001/cancel')->assertForbidden();
        $this->artisan('orders:expire-test')->assertFailed();
        $this->assertDatabaseCount('orders', 1);
        $this->app->instance('env', 'testing');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/me/orders')->assertUnauthorized();
    }
}
