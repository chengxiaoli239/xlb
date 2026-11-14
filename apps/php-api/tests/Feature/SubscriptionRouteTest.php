<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SubscriptionRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_create_a_test_route_and_publish_it(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        DB::table('nodes')->insert([
            'id' => 'node-test-2', 'name' => '演示节点 02', 'region' => '测试区域 B', 'protocol' => 'SOCKS5',
            'endpoint_host' => '198.51.100.11', 'endpoint_port' => 1080, 'status' => 'available', 'is_test' => true,
            'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 42, 'health_status' => 'verified_test',
            'last_health_check_at' => now()->toIso8601String(), 'config_version' => 1, 'updated_at' => now()->toIso8601String(),
        ]);

        $id = $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'test', 'node_ids' => ['node-test-2'], 'note' => '测试线路版本',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/canary', ['percent' => 100])
            ->assertOk()->assertJsonPath('data.status', 'canary')->assertJsonPath('data.canary_percent', 100);
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/publish')
            ->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('subscription_route_versions', ['id' => $id, 'scope' => 'test', 'status' => 'published', 'version' => 2]);
        $this->assertDatabaseHas('subscription_route_nodes', ['route_version_id' => $id, 'node_id' => 'node-test-2']);
        $this->assertDatabaseHas('subscription_route_versions', ['version' => 1, 'status' => 'draining']);
    }

    public function test_member_cannot_manage_subscription_routes(): void
    {
        $this->actingAs(User::findOrFail('user-member'));

        $this->getJson('/api/v1/admin/subscription-routes')->assertForbidden();
        $this->postJson('/api/v1/admin/subscription-routes', ['scope' => 'test', 'node_ids' => ['node-test-1']])->assertForbidden();
    }

    public function test_production_route_cannot_include_test_nodes(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));

        $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'production', 'node_ids' => ['node-test-1'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'route_nodes_not_ready');
    }

    public function test_route_rejects_nodes_without_health_acceptance(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        DB::table('nodes')->where('id', 'node-test-1')->update(['health_status' => 'not_connected']);

        $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'test', 'node_ids' => ['node-test-1'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'route_nodes_not_ready');
    }

    public function test_subscription_uses_published_route_and_not_a_free_floating_node(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $url = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data.url');

        DB::table('nodes')->where('id', 'node-test-1')->update(['status' => 'draining']);
        $this->get($url)->assertNotFound();

        $this->actingAs(User::findOrFail('user-admin'));
        $this->getJson('/api/v1/admin/subscription-routes')->assertOk()
            ->assertJsonPath('data.items.0.status', 'published')
            ->assertJsonPath('data.items.0.nodes.0.id', 'node-test-1');
    }

    public function test_subscription_profile_contains_all_nodes_in_published_route(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        DB::table('nodes')->insert([
            'id' => 'node-test-2', 'name' => '演示节点 02', 'region' => '测试区域 B', 'protocol' => 'SOCKS5',
            'endpoint_host' => '198.51.100.11', 'endpoint_port' => 1080, 'status' => 'available', 'is_test' => true,
            'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 42, 'health_status' => 'verified_test',
            'last_health_check_at' => now()->toIso8601String(), 'config_version' => 1, 'updated_at' => now()->toIso8601String(),
        ]);
        $id = $this->postJson('/api/v1/admin/subscription-routes', ['scope' => 'test', 'node_ids' => ['node-test-1', 'node-test-2']])->json('data.id');
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/canary', ['percent' => 100])->assertOk();
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/publish')->assertOk();

        $this->actingAs(User::findOrFail('user-member'));
        $url = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('X-XLB-Route-Version', '2')
            ->assertSee('198.51.100.10')->assertSee('198.51.100.11')->assertSee('演示节点 01')->assertSee('演示节点 02');
    }

    public function test_published_route_can_be_rolled_back_to_previous_version(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        DB::table('nodes')->insert([
            'id' => 'node-test-2', 'name' => '演示节点 02', 'region' => '测试区域 B', 'protocol' => 'SOCKS5',
            'endpoint_host' => '198.51.100.11', 'endpoint_port' => 1080, 'status' => 'available', 'is_test' => true,
            'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 42, 'health_status' => 'verified_test',
            'last_health_check_at' => now()->toIso8601String(), 'config_version' => 1, 'updated_at' => now()->toIso8601String(),
        ]);
        $id = $this->postJson('/api/v1/admin/subscription-routes', ['scope' => 'test', 'node_ids' => ['node-test-2']])->json('data.id');
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/canary', ['percent' => 100])->assertOk();
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/publish')->assertOk();
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/rollback')->assertOk()->assertJsonPath('data.status', 'rolled_back');

        $this->assertDatabaseHas('subscription_route_versions', ['version' => 1, 'status' => 'published']);
        $this->assertDatabaseHas('subscription_route_versions', ['id' => $id, 'status' => 'rolled_back']);
    }

    public function test_reconcile_retires_a_route_after_its_drain_window(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        DB::table('nodes')->insert([
            'id' => 'node-test-2', 'name' => '演示节点 02', 'region' => '测试区域 B', 'protocol' => 'SOCKS5',
            'endpoint_host' => '198.51.100.11', 'endpoint_port' => 1080, 'status' => 'available', 'is_test' => true,
            'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 42, 'health_status' => 'verified_test',
            'last_health_check_at' => now()->toIso8601String(), 'config_version' => 1, 'updated_at' => now()->toIso8601String(),
        ]);
        $id = $this->postJson('/api/v1/admin/subscription-routes', ['scope' => 'test', 'node_ids' => ['node-test-2']])->json('data.id');
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/canary', ['percent' => 100])->assertOk();
        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/publish')->assertOk();

        $this->travel(901)->seconds();
        $this->assertSame(1, app(SubscriptionRouteService::class)->reconcile());
        $this->assertDatabaseHas('subscription_route_versions', ['id' => 'route-test-v1', 'status' => 'retired']);
    }
}
