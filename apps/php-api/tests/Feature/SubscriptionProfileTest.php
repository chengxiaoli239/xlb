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
                $this->assertSame('select', $global['type']);
                $this->assertSame(['迅连宝测试'], $global['proxies']);
                $this->assertSame('198.51.100.10', $config['proxies'][0]['server']);
                $this->assertFalse($config['allow-lan']);
                $this->assertArrayNotHasKey('external-controller', $config);
                $this->assertArrayNotHasKey('tun', $config);
            }
        }
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
        foreach (['GLOBAL', 'DIRECT', 'REJECT', '迅连宝测试'] as $name) {
            DB::table('nodes')->where('id', 'node-test-1')->update(['name' => $name]);
            $response = $this->get($url.'?mode=global')->assertOk();
            $config = Yaml::parse($response->getContent());
            $nodeName = $config['proxies'][0]['name'];
            $this->assertNotContains($nodeName, array_column($config['proxy-groups'], 'name'));
            $this->assertNotContains($nodeName, ['DIRECT', 'REJECT']);
            $this->assertSame($nodeName, $config['proxy-groups'][0]['proxies'][0]);
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
