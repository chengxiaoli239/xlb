<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_default_profile_remains_clash_yaml_and_negotiates_supported_modes(): void
    {
        $url = $this->profileUrl();
        $response = $this->get($url)->assertOk()->assertHeader('X-XLB-Profile-Format', 'clash')
            ->assertHeader('X-XLB-Profile-Scope', 'local-test')->assertHeader('Content-Type', 'text/yaml; charset=utf-8')
            ->assertHeader('Vary', 'User-Agent, Accept')->assertHeader('Profile-Update-Interval', '6');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('total=', $response->headers->get('Subscription-Userinfo'));
        $this->assertSame('rule', Yaml::parse($response->getContent())['mode']);

        foreach (['clash', 'mihomo'] as $format) {
            foreach (['rule', 'global', 'direct'] as $mode) {
                $result = $this->get($url.'?format='.$format.'&mode='.$mode)->assertOk()->assertHeader('X-XLB-Profile-Format', $format);
                $config = Yaml::parse($result->getContent());
                $this->assertSame($mode, $config['mode']);
                $global = collect($config['proxy-groups'])->firstWhere('name', 'GLOBAL');
                $manual = collect($config['proxy-groups'])->firstWhere('name', '迅连宝-手动');
                $this->assertSame('select', $global['type']);
                $this->assertSame(['迅连宝-手动'], $global['proxies']);
                $this->assertContains($config['proxies'][0]['name'], $manual['proxies']);
                $this->assertSame('198.51.100.10', $config['proxies'][0]['server']);
                $this->assertFalse($config['allow-lan']);
                $this->assertArrayNotHasKey('external-controller', $config);
                $this->assertArrayNotHasKey('tun', $config);
            }
        }
    }

    public function test_account_route_profile_exposes_auto_fallback_and_manual_groups_from_published_nodes(): void
    {
        $url = $this->profileUrl();
        $now = now()->utc()->toIso8601String();
        DB::table('nodes')->insert([
            'id' => 'node-test-2', 'name' => '演示节点 02', 'region' => '测试区域', 'protocol' => 'SOCKS5',
            'endpoint_host' => '198.51.100.11', 'endpoint_port' => 1081, 'status' => 'available',
            'is_test' => true, 'max_sessions' => 100, 'active_sessions' => 5, 'latency_ms' => 55,
            'health_status' => 'verified_test', 'last_health_check_at' => $now, 'config_version' => 1, 'updated_at' => $now,
        ]);
        DB::table('subscription_route_nodes')->insert([
            'id' => 'route-test-v1-node-2', 'route_version_id' => 'route-test-v1', 'node_id' => 'node-test-2',
            'weight' => 100, 'created_at' => $now,
        ]);

        $response = $this->get($url)->assertOk();
        $config = Yaml::parse($response->getContent());
        $nodeNames = array_column($config['proxies'], 'name');
        $automatic = collect($config['proxy-groups'])->firstWhere('name', '迅连宝-自动');
        $fallback = collect($config['proxy-groups'])->firstWhere('name', '迅连宝-故障切换');
        $manual = collect($config['proxy-groups'])->firstWhere('name', '迅连宝-手动');
        $global = collect($config['proxy-groups'])->firstWhere('name', 'GLOBAL');

        $this->assertSame(['演示节点 01', '演示节点 02'], $nodeNames);
        $this->assertSame('url-test', $automatic['type']);
        $this->assertSame($nodeNames, $automatic['proxies']);
        $this->assertSame('https://www.gstatic.com/generate_204', $automatic['url']);
        $this->assertSame(300, $automatic['interval']);
        $this->assertSame(50, $automatic['tolerance']);
        $this->assertSame('fallback', $fallback['type']);
        $this->assertSame($nodeNames, $fallback['proxies']);
        $this->assertSame('https://www.gstatic.com/generate_204', $fallback['url']);
        $this->assertSame(300, $fallback['interval']);
        $this->assertSame('select', $manual['type']);
        $this->assertSame(['迅连宝-自动', '迅连宝-故障切换', ...$nodeNames, 'DIRECT'], $manual['proxies']);
        $this->assertSame(['迅连宝-手动'], $global['proxies']);
        $this->assertSame(['MATCH,迅连宝-手动'], $config['rules']);
        $this->assertSame('local-test', $response->headers->get('X-XLB-Profile-Scope'));
    }

    public function test_route_groups_exclude_unhealthy_and_stale_nodes(): void
    {
        $now = now()->utc();
        foreach ([
            ['node-test-2', '不健康节点', '198.51.100.11', 'unhealthy', $now],
            ['node-test-3', '观测过期节点', '198.51.100.12', 'verified_test', $now->copy()->subMinutes(10)],
        ] as [$nodeId, $nodeName, $endpointHost, $healthStatus, $lastHealthCheckAt]) {
            DB::table('nodes')->insert([
                'id' => $nodeId, 'name' => $nodeName, 'region' => '测试区域', 'protocol' => 'SOCKS5',
                'endpoint_host' => $endpointHost, 'endpoint_port' => 1080, 'status' => 'available',
                'is_test' => true, 'max_sessions' => 100, 'active_sessions' => 0, 'latency_ms' => 60,
                'health_status' => $healthStatus, 'last_health_check_at' => $lastHealthCheckAt->toIso8601String(),
                'config_version' => 1, 'updated_at' => $now->toIso8601String(),
            ]);
            DB::table('subscription_route_nodes')->insert([
                'id' => 'route-test-v1-'.$nodeId, 'route_version_id' => 'route-test-v1', 'node_id' => $nodeId,
                'weight' => 100, 'created_at' => $now->toIso8601String(),
            ]);
        }

        $url = $this->profileUrl();
        $response = $this->get($url)->assertOk();
        $config = Yaml::parse($response->getContent());
        $nodeNames = array_column($config['proxies'], 'name');

        $this->assertSame(['演示节点 01'], $nodeNames);
        foreach (['迅连宝-自动', '迅连宝-故障切换'] as $groupName) {
            $group = collect($config['proxy-groups'])->firstWhere('name', $groupName);
            $this->assertSame($nodeNames, $group['proxies']);
        }
        $manual = collect($config['proxy-groups'])->firstWhere('name', '迅连宝-手动');
        $this->assertSame(['迅连宝-自动', '迅连宝-故障切换', '演示节点 01', 'DIRECT'], $manual['proxies']);
        $this->assertStringNotContainsString('不健康节点', $response->getContent());
        $this->assertStringNotContainsString('观测过期节点', $response->getContent());
    }

    public function test_user_agent_negotiation_and_explicit_format_precedence(): void
    {
        $url = $this->profileUrl();
        $this->withHeader('User-Agent', 'mihomo/1.19.0')->get($url)->assertOk()->assertHeader('X-XLB-Profile-Format', 'mihomo');
        $this->withHeader('User-Agent', 'sing-box/1.12')->get($url)->assertStatus(406)->assertJsonPath('error.code', 'format_not_supported');
        $this->get($url.'?format=clash')->assertOk()->assertHeader('X-XLB-Profile-Format', 'clash');
        $this->withHeaders(['User-Agent' => 'browser', 'Accept' => 'application/json'])->get($url)->assertStatus(406);
    }

    public function test_invalid_options_are_rejected_without_recording_successful_use(): void
    {
        $url = $this->profileUrl();
        foreach (['format=sing-box', 'format=unknown', 'format[]=clash'] as $query) {
            $this->get($url.'?'.$query)->assertStatus(406)->assertJsonPath('error.code', 'format_not_supported');
        }
        foreach (['mode=invalid', 'mode[]=rule'] as $query) {
            $this->get($url.'?'.$query)->assertStatus(400)->assertJsonPath('error.code', 'invalid_mode');
        }
        $this->assertNull(DB::table('subscriptions')->value('last_used_at'));
    }

    public function test_expired_credentials_and_inactive_users_cannot_fetch_profiles(): void
    {
        $url = $this->profileUrl();
        DB::table('subscriptions')->update(['expires_at' => now()->subSecond()->toIso8601String()]);
        $this->get($url)->assertNotFound();
        DB::table('subscriptions')->update(['expires_at' => null]);
        DB::table('users')->where('id', 'user-member')->update(['status' => 'disabled']);
        $this->get($url)->assertNotFound();
    }

    public function test_expired_or_exhausted_membership_does_not_return_nodes(): void
    {
        $url = $this->profileUrl();
        DB::table('entitlements')->where('user_id', 'user-member')->update(['data_used_gb' => 100000]);
        $this->get($url)->assertNotFound();
        DB::table('entitlements')->where('user_id', 'user-member')->update(['data_used_gb' => 0, 'ends_at' => now()->subSecond()->toIso8601String()]);
        $this->get($url)->assertNotFound();
        $this->get('/subscribe/invalid?format=unknown')->assertNotFound()->assertDontSee('198.51.100.10');
    }

    public function test_yaml_escapes_node_names_and_version_changes_with_configuration(): void
    {
        $url = $this->profileUrl();
        $version = $this->get($url)->headers->get('X-XLB-Config-Version');
        $name = "演示: \"quoted\"\nmode: direct";
        DB::table('nodes')->where('id', 'node-test-1')->update(['name' => $name]);
        $response = $this->get($url)->assertOk();
        $config = Yaml::parse($response->getContent());
        $this->assertSame($name, $config['proxies'][0]['name']);
        $this->assertSame($name, $config['proxy-groups'][0]['proxies'][0]);
        $this->assertSame('rule', $config['mode']);
        $this->assertNotSame($version, $response->headers->get('X-XLB-Config-Version'));
    }

    public function test_generated_profiles_are_accepted_by_the_configured_mihomo_core(): void
    {
        $binary = getenv('XLB_MIHOMO_BINARY');
        if (! is_string($binary) || $binary === '') {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                $this->fail('Hosted CI must provide the pinned Mihomo parser binary.');
            }
            $this->markTestSkipped('Set XLB_MIHOMO_BINARY to run the optional Mihomo parser check.');
        }
        $this->assertFileExists($binary);

        $url = $this->profileUrl();
        DB::table('nodes')->where('id', 'node-test-1')->update(['name' => 'GLOBAL']);
        foreach (['clash', 'mihomo'] as $format) {
            foreach (['rule', 'global', 'direct'] as $mode) {
                $response = $this->get($url.'?format='.$format.'&mode='.$mode)->assertOk();
                $profilePath = tempnam(sys_get_temp_dir(), 'xlb-profile-');
                if ($profilePath === false) {
                    $this->fail('Unable to allocate a temporary profile file.');
                }

                try {
                    $this->assertNotFalse(file_put_contents($profilePath, $response->getContent()));
                    $process = new Process([$binary, '-t', '-f', $profilePath]);
                    $process->setTimeout(10);
                    $exitCode = $process->run();
                    $this->assertSame(0, $exitCode, $process->getErrorOutput().$process->getOutput());
                } finally {
                    if (is_file($profilePath)) {
                        unlink($profilePath);
                    }
                }
            }
        }
    }

    public function test_reserved_node_names_do_not_collide_with_policy_groups(): void
    {
        $url = $this->profileUrl();
        foreach ([
            'GLOBAL', 'DIRECT', 'REJECT', '迅连宝测试', '迅连宝-手动', '迅连宝-自动', '迅连宝-故障切换',
        ] as $name) {
            DB::table('nodes')->where('id', 'node-test-1')->update(['name' => $name]);
            $response = $this->get($url.'?mode=global')->assertOk();
            $config = Yaml::parse($response->getContent());
            $nodeName = $config['proxies'][0]['name'];
            $this->assertNotContains($nodeName, array_column($config['proxy-groups'], 'name'));
            $this->assertNotContains($nodeName, ['DIRECT', 'REJECT']);
            $manual = collect($config['proxy-groups'])->firstWhere('name', '迅连宝-手动');
            $this->assertContains($nodeName, $manual['proxies']);
        }
    }

    public function test_rotation_and_revocation_apply_to_every_format_and_mode(): void
    {
        $url = $this->profileUrl();
        $id = DB::table('subscriptions')->value('id');
        $next = $this->postJson('/api/v1/me/subscriptions/'.$id.'/rotate')->assertOk()->json('data.url');
        foreach (['clash', 'mihomo'] as $format) {
            $this->get($url.'?format='.$format.'&mode=global')->assertNotFound();
            $this->get($next.'?format='.$format.'&mode=direct')->assertOk();
        }
        $this->postJson('/api/v1/me/subscriptions/'.$id.'/revoke')->assertNoContent();
        $this->get($next.'?format=mihomo')->assertNotFound();
    }

    public function test_account_wide_credential_is_not_issued_or_served_outside_local_test_scope(): void
    {
        $url = $this->profileUrl();
        config(['subscription_routes.scope' => 'production']);

        $this->getJson('/api/v1/me/subscriptions')->assertOk()->assertJsonPath('data.0.url', null);
        $this->postJson('/api/v1/me/subscriptions')->assertStatus(409)
            ->assertJsonPath('error.code', 'legacy_account_credential_disabled');
        $this->postJson('/api/v1/me/subscriptions/'.DB::table('subscriptions')->value('id').'/rotate')
            ->assertStatus(409)->assertJsonPath('error.code', 'legacy_account_credential_disabled');
        $this->get($url)->assertNotFound();
    }

    public function test_account_wide_credential_is_disabled_in_production_even_if_test_scope_is_misconfigured(): void
    {
        $url = $this->profileUrl();
        $this->withSession(['_token' => 'subscription-test-csrf'])->withHeader('X-CSRF-TOKEN', 'subscription-test-csrf');
        $this->app->instance('env', 'production');

        $this->getJson('/api/v1/me/subscriptions')->assertOk()->assertJsonPath('data.0.url', null);
        $this->postJson('/api/v1/me/subscriptions')->assertStatus(409)
            ->assertJsonPath('error.code', 'legacy_account_credential_disabled');
        $this->get($url)->assertNotFound();
    }

    public function test_prepaid_contiguous_renewal_extends_profile_expiry_without_changing_credentials_or_current_quota(): void
    {
        $url = $this->profileUrl();
        $currentEnd = DB::table('entitlements')->where('id', 'entitlement-member-annual')->value('ends_at');
        $currentUsage = explode('; expire=', $this->get($url)->headers->get('Subscription-Userinfo'))[0];
        $renewalEnd = now()->parse($currentEnd)->addMonth()->toIso8601String();
        DB::table('entitlements')->insert([
            'id' => 'prepaid-renewal', 'user_id' => 'user-member', 'plan_id' => 'annual', 'status' => 'active',
            'starts_at' => $currentEnd, 'ends_at' => $renewalEnd, 'data_used_gb' => 0,
        ]);
        DB::table('entitlements')->insert([
            'id' => 'future-with-gap', 'user_id' => 'user-member', 'plan_id' => 'annual', 'status' => 'active',
            'starts_at' => now()->parse($renewalEnd)->addDay()->toIso8601String(),
            'ends_at' => now()->parse($renewalEnd)->addYear()->toIso8601String(), 'data_used_gb' => 0,
        ]);
        $response = $this->get($url)->assertOk()->assertHeader('X-XLB-Expires-At', $renewalEnd);
        $this->assertSame($currentUsage, explode('; expire=', $response->headers->get('Subscription-Userinfo'))[0]);
        $this->getJson('/api/v1/me/subscriptions')->assertJsonPath('data.0.url', $url);
        $this->assertDatabaseCount('subscriptions', 1);
        DB::table('subscriptions')->update(['expires_at' => $currentEnd]);
        $this->get($url)->assertOk()->assertHeader('X-XLB-Expires-At', $currentEnd);
    }

    private function profileUrl(): string
    {
        $this->actingAs(User::findOrFail('user-member'));

        return $this->postJson('/api/v1/me/subscriptions')->assertCreated()->json('data.url');
    }
}
