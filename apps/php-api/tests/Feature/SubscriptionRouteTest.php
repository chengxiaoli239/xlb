<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseNodeHealthStateStore;
use App\Services\NodeHealthState;
use App\Services\SubscriptionRouteService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_route_rejects_nodes_with_stale_health_checks(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        $staleAt = now()->subSeconds(301)->toIso8601String();
        DB::table('nodes')->where('id', 'node-test-1')->update(['last_health_check_at' => $staleAt]);

        $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'test', 'node_ids' => ['node-test-1'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'route_nodes_not_ready');
    }

    public function test_route_probe_filter_is_disabled_by_default(): void
    {
        $this->assertSame(false, config('node_health_probe.route_filter_enabled', false));

        $route = app(SubscriptionRouteService::class)->resolve('user-member', 'test');

        $this->assertSame(['node-test-1'], array_map(
            static fn (object $node): string => $node->id,
            $route['nodes'],
        ));
    }

    public function test_route_resolution_excludes_missing_stale_and_open_probe_states(): void
    {
        config([
            'node_health_probe.route_filter_enabled' => true,
            'node_health_probe.route_state_ttl_seconds' => 30,
        ]);
        $this->addTestRouteCandidate('node-test-2');
        $this->addTestRouteCandidate('node-test-3');
        $this->addTestRouteCandidate('node-test-4');

        $now = new DateTimeImmutable;
        $store = new DatabaseNodeHealthStateStore;
        $store->put('node-test-1', new NodeHealthState('healthy', 0, 0, $now->modify('-10 seconds')));
        $store->put('node-test-2', new NodeHealthState('unhealthy', 3, 0, $now->modify('-10 seconds')));
        $store->put('node-test-3', new NodeHealthState('healthy', 0, 0, $now->modify('-31 seconds')));

        $route = app(SubscriptionRouteService::class)->resolve('user-member', 'test');

        $this->assertSame(['node-test-1'], array_map(
            static fn (object $node): string => $node->id,
            $route['nodes'],
        ));
        $this->assertSame('verified_test', DB::table('nodes')->where('id', 'node-test-1')->value('health_status'));
        $this->assertSame('verified_test', DB::table('nodes')->where('id', 'node-test-2')->value('health_status'));
    }

    public function test_admin_cannot_add_route_candidate_without_a_fresh_probe_state_when_filter_is_enabled(): void
    {
        config(['node_health_probe.route_filter_enabled' => true]);
        $this->actingAs(User::findOrFail('user-admin'));

        $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'test', 'node_ids' => ['node-test-1'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'route_nodes_not_ready');

        (new DatabaseNodeHealthStateStore)->put(
            'node-test-1',
            new NodeHealthState('healthy', 0, 0, new DateTimeImmutable),
        );

        $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'test', 'node_ids' => ['node-test-1'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
    }

    public function test_first_production_route_cannot_start_a_partial_canary(): void
    {
        $this->actingAs(User::findOrFail('user-admin'));
        DB::table('nodes')->insert([
            'id' => 'node-production-1', 'name' => '生产候选节点', 'region' => '测试区域', 'protocol' => 'SS2022',
            'endpoint_host' => '198.51.100.12', 'endpoint_port' => 18443, 'status' => 'available', 'is_test' => false,
            'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 42, 'health_status' => 'healthy',
            'last_health_check_at' => now()->toIso8601String(), 'config_version' => 1, 'updated_at' => now()->toIso8601String(),
        ]);

        $id = $this->postJson('/api/v1/admin/subscription-routes', [
            'scope' => 'production', 'node_ids' => ['node-production-1'],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/admin/subscription-routes/'.$id.'/canary', ['percent' => 25])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'route_transition_invalid');

        $this->assertDatabaseHas('subscription_route_versions', ['id' => $id, 'status' => 'draft']);

        DB::table('subscription_route_versions')->where('id', $id)->update([
            'status' => 'canary', 'canary_percent' => 25,
        ]);
        $this->assertNull(app(SubscriptionRouteService::class)->resolve('user-member', 'production'));
    }

    public function test_subscription_omits_nodes_with_stale_health_checks(): void
    {
        $this->actingAs(User::findOrFail('user-member'));
        $url = $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data.url');
        DB::table('nodes')->where('id', 'node-test-1')->update([
            'last_health_check_at' => now()->subSeconds(301)->toIso8601String(),
        ]);

        $this->get($url)->assertNotFound();
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

    private function addTestRouteCandidate(string $nodeId): void
    {
        DB::table('nodes')->insert([
            'id' => $nodeId, 'name' => $nodeId, 'region' => '测试区域', 'protocol' => 'SOCKS5',
            'endpoint_host' => '198.51.100.20', 'endpoint_port' => 1080, 'status' => 'available', 'is_test' => true,
            'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 40, 'health_status' => 'verified_test',
            'last_health_check_at' => now()->toIso8601String(), 'config_version' => 1, 'updated_at' => now()->toIso8601String(),
        ]);
        DB::table('subscription_route_nodes')->insert([
            'id' => (string) Str::uuid(), 'route_version_id' => 'route-test-v1', 'node_id' => $nodeId,
            'weight' => 100, 'created_at' => now()->toIso8601String(),
        ]);
    }
}
