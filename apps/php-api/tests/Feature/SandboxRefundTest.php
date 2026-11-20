<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NodeAccessRevocationProcessor;
use App\Services\NodePeerRevocationDriver;
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

    public function test_refund_queues_peer_revocation_and_persists_final_traffic_on_acknowledgement(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();
        $peerId = $this->createPeer($member->id);

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '撤销节点测试凭证'])
            ->assertCreated()
            ->assertJsonPath('data.node_revocations.status', 'pending')
            ->assertJsonPath('data.node_revocations.pending_count', 1)
            ->assertJsonPath('data.node_revocations.confirmed_count', 0);

        $job = DB::table('node_access_revocation_outbox')->where('peer_id', $peerId)->first();
        $this->assertSame('pending', $job->status);
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'revocation_pending']);

        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', $peerId)->first();
        $driver->shouldReceive('revoke')->once()->andReturn([
            'node_id' => DB::table('node_access_peers')->where('id', $peerId)->value('node_id'),
            'peer_id' => $peerId,
            'request_id' => $job->id,
            'generation' => (int) $job->generation,
            'acknowledgement_id' => 'sandbox-ack-001',
            'upload_bytes' => 900,
            'download_bytes' => 2780,
            'confirmation_status' => 'confirmed_test',
        ]);

        $this->assertSame(1, app(NodeAccessRevocationProcessor::class)->processDue());
        $this->assertDatabaseHas('node_access_revocation_outbox', [
            'id' => $job->id,
            'status' => 'confirmed_test',
            'acknowledgement_id' => 'sandbox-ack-001',
            'final_upload_bytes' => 1000,
            'final_download_bytes' => 2780,
        ]);
        $this->assertDatabaseHas('node_access_peers', [
            'id' => $peerId,
            'status' => 'revoked',
            'upload_bytes' => 1000,
            'download_bytes' => 2780,
        ]);
        $this->assertSame(0, app(NodeAccessRevocationProcessor::class)->processDue());

        $this->getJson('/api/v1/admin/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.node_revocations.status', 'confirmed_test')
            ->assertJsonPath('data.node_revocations.confirmed_count', 1);
    }

    public function test_peer_revocation_failure_keeps_peer_pending_and_retries_without_leaking_driver_error(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();
        $peerId = $this->createPeer($member->id);

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '验证节点撤权重试'])
            ->assertCreated();

        config(['node_access.enabled' => true, 'node_access.driver' => 'mock']);
        $driver = $this->mock(NodePeerRevocationDriver::class);
        $driver->shouldReceive('revoke')->once()->andThrow(new \RuntimeException('private endpoint and credential'));
        $processor = app(NodeAccessRevocationProcessor::class);

        $this->assertSame(1, $processor->processDue());
        $job = DB::table('node_access_revocation_outbox')->where('peer_id', $peerId)->first();
        $this->assertSame('retry', $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertSame('provider_error', $job->last_error_code);
        $this->assertNotNull($job->next_attempt_at);
        $this->assertStringNotContainsString('private endpoint', $job->last_error_code);
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'revocation_pending']);
        $this->assertSame(0, $processor->processDue());

        $this->travel(11)->seconds();
        $retryDriver = $this->mock(NodePeerRevocationDriver::class);
        $nodeId = (string) DB::table('node_access_peers')->where('id', $peerId)->value('node_id');
        $retryDriver->shouldReceive('revoke')->once()->andReturn([
            'node_id' => $nodeId,
            'peer_id' => $peerId,
            'request_id' => $job->id,
            'generation' => (int) $job->generation,
            'acknowledgement_id' => 'sandbox-ack-retry',
            'upload_bytes' => 1400,
            'download_bytes' => 2800,
            'confirmation_status' => 'confirmed_test',
        ]);
        $processor = app(NodeAccessRevocationProcessor::class);
        $this->assertSame(1, $processor->processDue());
        $this->assertDatabaseHas('node_access_revocation_outbox', ['id' => $job->id, 'status' => 'confirmed_test']);
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'revoked']);
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
        $peerId = $this->createPeer('user-member');
        $grant = DB::table('entitlement_grants')->where('order_id', $orderId)->first();
        $subscriptionUrl = $this->postJson('/api/v1/me/subscriptions')->assertOk()->json('data.url');

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '撤销该笔续费权益'])
            ->assertCreated();

        $this->assertDatabaseHas('entitlements', ['id' => 'entitlement-member-annual', 'status' => 'active']);
        $this->assertDatabaseHas('entitlements', ['id' => $grant->entitlement_id, 'status' => 'revoked_test']);
        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'active']);
        $this->assertDatabaseCount('node_access_revocation_outbox', 0);
        $this->get($subscriptionUrl)->assertOk();
    }

    public function test_future_renewal_does_not_keep_refunded_peer_authorized_today(): void
    {
        $member = User::factory()->create(['id' => (string) Str::uuid(), 'role' => 'member']);
        $this->actingAs($member);
        $orderId = $this->createPaidOrder();
        $peerId = $this->createPeer($member->id);
        $subscriptionUrl = $this->postJson('/api/v1/me/subscriptions')->assertOk()->json('data.url');
        DB::table('entitlements')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $member->id, 'plan_id' => 'annual',
            'status' => 'active', 'starts_at' => now()->addMonth()->toIso8601String(),
            'ends_at' => now()->addYear()->toIso8601String(), 'data_used_gb' => 0,
        ]);

        $this->actingAs(User::findOrFail('user-admin'))
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$orderId.'/sandbox-refund', ['reason' => '未来续费未生效'])
            ->assertCreated()
            ->assertJsonPath('data.node_revocations.status', 'pending');

        $this->assertDatabaseHas('node_access_peers', ['id' => $peerId, 'status' => 'revocation_pending']);
        $this->get($subscriptionUrl)->assertNotFound();
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

    private function createPeer(string $userId): string
    {
        $now = now()->utc()->toIso8601String();
        $nodeId = (string) Str::uuid();
        $peerId = (string) Str::uuid();
        DB::table('nodes')->insert([
            'id' => $nodeId, 'name' => '撤权测试节点', 'region' => '测试区域', 'protocol' => 'WireGuard',
            'endpoint_host' => '127.0.0.1', 'endpoint_port' => 51820, 'status' => 'available', 'is_test' => true,
            'max_sessions' => 10, 'active_sessions' => 1, 'latency_ms' => null, 'updated_at' => $now,
            'health_status' => 'verified_test', 'last_health_check_at' => $now, 'config_version' => 1,
        ]);
        DB::table('node_access_peers')->insert([
            'id' => $peerId, 'user_id' => $userId, 'node_id' => $nodeId,
            'device_id' => 'device-'.$peerId, 'device_name' => '测试设备', 'public_key' => 'public-'.$peerId,
            'device_key_fingerprint' => str_repeat('c', 64), 'generation' => 1,
            'expires_at' => now()->addYear()->utc()->toIso8601String(),
            'credential_ciphertext' => encrypt('sandbox-peer-credential'), 'profile_ciphertext' => null,
            'provision_request_id' => 'provision-'.$peerId,
            'status' => 'active', 'upload_bytes' => 1000, 'download_bytes' => 2000,
            'last_observed_at' => $now, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $peerId;
    }
}
