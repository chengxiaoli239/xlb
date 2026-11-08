<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NodeAutomation;
use App\Services\NodeProviderRegistry;
use App\Services\NodeProviders\AwsNodeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class NodeAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['node_automation.enabled' => true]);
    }

    private function admin(): self
    {
        $this->actingAs(User::findOrFail('user-admin'));

        return $this;
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['name' => '美国测试', 'request_id' => (string) Str::uuid(), 'instance_type' => 't3.micro', 'lifetime_minutes' => 15], $overrides);
    }

    private function createDeployment(): object
    {
        return app(NodeAutomation::class)->create($this->input(), 'user-admin');
    }

    private function mockAwsProvider(): MockInterface
    {
        $provider = $this->mock(AwsNodeProvider::class);
        $provider->shouldReceive('resourceKey')->byDefault()->andReturnUsing(
            fn (string $deploymentKey): string => 'xlb-auto-'.substr($deploymentKey, strlen('xlb-node-'))
        );

        return $provider;
    }

    public function test_only_admin_can_create_read_retire_and_download_test_nodes(): void
    {
        $this->getJson('/api/v1/admin/node-deployments')->assertUnauthorized();
        $row = $this->createDeployment();
        $this->actingAs(User::findOrFail('user-member'));
        $this->getJson('/api/v1/admin/node-deployments')->assertForbidden();
        $this->postJson('/api/v1/admin/node-deployments', $this->input())->assertForbidden();
        $this->postJson('/api/v1/admin/node-deployments/'.$row->id.'/retire')->assertForbidden();
        $this->getJson('/api/v1/admin/node-deployments/'.$row->id.'/profile')->assertForbidden();
    }

    public function test_api_authentication_failure_is_json_without_accept_header(): void
    {
        $this->get('/api/v1/admin/node-deployments')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_deployment_persists_its_provider_and_provider_neutral_key(): void
    {
        $row = $this->createDeployment();
        $deploymentKey = 'xlb-node-'.str_replace('-', '', $row->id);

        $this->assertSame('aws', $row->provider);
        $this->assertSame($deploymentKey, $row->deployment_key);
        $this->assertSame('xlb-auto-'.substr($deploymentKey, strlen('xlb-node-')), $row->provider_resource_key);
        $this->assertDatabaseHas('node_deployments', [
            'id' => $row->id,
            'provider' => 'aws',
            'deployment_key' => $deploymentKey,
            'provider_resource_key' => 'xlb-auto-'.substr($deploymentKey, strlen('xlb-node-')),
        ]);
    }

    public function test_admin_deployment_list_exposes_provider_and_provider_resource_key(): void
    {
        $row = $this->createDeployment();
        $this->admin()->getJson('/api/v1/admin/node-deployments')
            ->assertOk()
            ->assertJsonPath('data.items.0.provider', 'aws')
            ->assertJsonPath('data.items.0.deployment_key', $row->deployment_key)
            ->assertJsonPath('data.items.0.provider_resource_key', $row->provider_resource_key);
    }

    public function test_existing_deployment_uses_its_persisted_provider_after_default_changes(): void
    {
        $row = $this->createDeployment();
        config(['node_automation.default_provider' => 'unconfigured']);

        $this->assertInstanceOf(AwsNodeProvider::class, app(NodeProviderRegistry::class)->resolve($row->provider));
        $retry = app(NodeAutomation::class)->create([
            'name' => DB::table('nodes')->where('id', $row->node_id)->value('name'),
            'request_id' => $row->request_id,
            'instance_type' => $row->instance_type,
            'lifetime_minutes' => 15,
        ], 'user-admin');
        $this->assertSame($row->id, $retry->id);
        $this->assertSame('aws', $retry->provider);
    }

    public function test_unknown_provider_is_rejected_without_falling_back_to_default(): void
    {
        $this->expectException(\RuntimeException::class);

        app(NodeProviderRegistry::class)->resolve('digitalocean');
    }

    public function test_aws_resource_names_are_derived_from_valid_provider_neutral_keys(): void
    {
        $provider = app(AwsNodeProvider::class);

        $this->assertSame(
            'xlb-auto-'.str_repeat('a', 32),
            $provider->resourceKey('xlb-node-'.str_repeat('a', 32))
        );

        $this->expectException(\RuntimeException::class);
        $provider->resourceKey('other-provider-resource');
    }

    public function test_creation_is_bounded_idempotent_and_never_calls_cloud_in_http(): void
    {
        $this->mockAwsProvider()->shouldNotReceive('create');
        $this->admin();
        $input = $this->input();
        $id = $this->postJson('/api/v1/admin/node-deployments', $input)->assertStatus(202)->json('data.id');
        $this->postJson('/api/v1/admin/node-deployments', $input)->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('node_deployments', 1);
        $this->postJson('/api/v1/admin/node-deployments', $this->input())->assertStatus(202);
        $this->postJson('/api/v1/admin/node-deployments', $this->input())->assertStatus(409);
    }

    public function test_region_and_size_cannot_be_overridden_and_disabled_creation_is_blocked(): void
    {
        $this->admin();
        foreach ([['region' => 'ap-east-1'], ['instance_type' => 'c5.xlarge'], ['lifetime_minutes' => 0], ['name' => '  '], ['resources' => ['InstanceId' => 'i-prod']]] as $override) {
            $this->postJson('/api/v1/admin/node-deployments', $this->input($override))->assertStatus(422);
        }
        config(['node_automation.enabled' => false]);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/admin/node-deployments', $this->input())->assertStatus(409);
    }

    public function test_full_progress_requires_protocol_verification_before_available(): void
    {
        $cloud = $this->mockAwsProvider();
        $cloud->shouldReceive('create')->once()->with(\Mockery::any())->andReturn(['resources' => ['InstanceId' => 'i-owned'], 'state' => 'building']);
        $cloud->shouldReceive('inspect')->once()->with(\Mockery::any())->andReturn(['state' => 'ready', 'resources' => ['PublicIp' => '198.51.100.1']]);
        $cloud->shouldReceive('probe')->once()->with(\Mockery::any())->andReturn(['verified' => true, 'public_ip' => '198.51.100.1']);
        $row = $this->createDeployment();
        $service = app(NodeAutomation::class);
        $service->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'provisioning']);
        $service->reconcile();
        $this->assertDatabaseHas('nodes', ['id' => $row->node_id, 'status' => 'candidate']);
        $service->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'available']);
        $this->assertDatabaseHas('nodes', ['id' => $row->node_id, 'status' => 'available', 'health_status' => 'verified_test']);
        $this->assertStringContainsString('InstanceId', $service->find($row->id)->resources);
    }

    public function test_retirement_drains_and_does_not_claim_released_when_cloud_is_partial(): void
    {
        $row = $this->createDeployment();
        $this->admin()->postJson('/api/v1/admin/node-deployments/'.$row->id.'/retire', [])->assertStatus(422);
        $this->postJson('/api/v1/admin/node-deployments/'.$row->id.'/retire', ['confirmation' => '永久下架并释放'])->assertStatus(202)->assertJsonPath('data.phase', 'draining');
        $cloud = $this->mock(AwsNodeProvider::class);
        $cloud->shouldReceive('release')->once()->with(\Mockery::any())->andReturn(['state' => 'deleting']);
        $service = app(NodeAutomation::class);
        $service->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'draining']);
        $this->travel(61)->seconds();
        $service->reconcile();
        $service->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'releasing', 'released_at' => null]);
    }

    public function test_expiry_reclaims_even_when_new_creation_disabled_and_retire_is_idempotent(): void
    {
        $row = $this->createDeployment();
        config(['node_automation.enabled' => false]);
        $this->travel(16)->minutes();
        $service = app(NodeAutomation::class);
        $service->reconcile();
        $requestedAt = $service->find($row->id)->release_requested_at;
        $service->retire($row->id, 'user-admin');
        $this->assertSame($requestedAt, $service->find($row->id)->release_requested_at);
        $this->travel(61)->seconds();
        $service->reconcile();
        $this->mock(AwsNodeProvider::class)->shouldReceive('release')->once()->with(\Mockery::any())->andReturn(['state' => 'released']);
        app(NodeAutomation::class)->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'released']);
        $this->assertDatabaseHas('nodes', ['id' => $row->node_id, 'status' => 'retired']);
    }

    public function test_unknown_creation_result_enters_cleanup_and_release_failure_retries_without_false_success(): void
    {
        $row = $this->createDeployment();
        $cloud = $this->mock(AwsNodeProvider::class);
        $cloud->shouldReceive('create')->once()->with(\Mockery::any())->andThrow(new \RuntimeException('secret-provider-error'));
        $cloud->shouldReceive('release')->once()->with(\Mockery::any())->andThrow(new \RuntimeException('secret-provider-error'));
        $service = app(NodeAutomation::class);
        $service->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'draining']);
        $this->travel(61)->seconds();
        $service->reconcile();
        $service->reconcile();
        $updated = $service->find($row->id);
        $this->assertSame('releasing', $updated->phase);
        $this->assertSame(1, $updated->attempts);
        $this->assertNotNull($updated->next_attempt_at);
        $this->assertStringNotContainsString('secret-provider-error', $updated->last_error);
    }

    public function test_generic_status_api_cannot_publish_or_release_managed_node(): void
    {
        $row = $this->createDeployment();
        $this->admin()->patchJson('/api/v1/admin/nodes/'.$row->node_id, ['status' => 'available'])->assertStatus(409);
        $this->getJson('/api/v1/admin/nodes/'.$row->node_id)->assertJsonPath('data.allowed_transitions', []);
        $this->getJson('/api/v1/admin/node-deployments/'.$row->id.'/profile')->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.deployment_requested', 'target_id' => $row->id]);
    }

    public function test_stalled_bootstrap_is_reclaimed_instead_of_remaining_billable(): void
    {
        $row = $this->createDeployment();
        DB::table('node_deployments')->where('id', $row->id)->update(['phase' => 'bootstrapping']);
        $this->travel(7)->minutes();
        $this->mock(AwsNodeProvider::class)->shouldReceive('inspect')->once()->with(\Mockery::any())->andReturn(['state' => 'bootstrapping']);
        app(NodeAutomation::class)->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'draining']);
    }

    public function test_used_idempotency_key_cannot_change_requested_size(): void
    {
        $input = $this->input();
        $this->admin()->postJson('/api/v1/admin/node-deployments', $input)->assertStatus(202);
        $this->postJson('/api/v1/admin/node-deployments', array_replace($input, ['instance_type' => 't3.small']))->assertStatus(409);
        $this->assertDatabaseCount('node_deployments', 1);
    }

    public function test_test_config_download_is_private_and_denied_after_expiry(): void
    {
        $row = $this->createDeployment();
        DB::table('node_deployments')->where('id', $row->id)->update(['phase' => 'available']);
        $this->mock(AwsNodeProvider::class)->shouldReceive('profile')->once()->with(\Mockery::any())
            ->andReturn(['profile' => '{"password":"test-only-credential"}']);
        $this->admin()->get('/api/v1/admin/node-deployments/'.$row->id.'/profile')->assertOk()
            ->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/admin/node-deployments')->assertDontSee('test-only-credential');
        $this->travel(16)->minutes();
        $this->get('/api/v1/admin/node-deployments/'.$row->id.'/profile')->assertStatus(409);
    }

    public function test_cloud_expiry_cleanup_is_synchronized_without_releasing_again(): void
    {
        $row = $this->createDeployment();
        DB::table('node_deployments')->where('id', $row->id)->update(['phase' => 'available']);
        $this->mock(AwsNodeProvider::class)->shouldReceive('inspect')->once()->with(\Mockery::any())->andReturn(['state' => 'released']);
        app(NodeAutomation::class)->reconcile();
        $this->assertDatabaseHas('node_deployments', ['id' => $row->id, 'phase' => 'released']);
        $this->assertDatabaseHas('nodes', ['id' => $row->node_id, 'status' => 'retired']);
    }
}
