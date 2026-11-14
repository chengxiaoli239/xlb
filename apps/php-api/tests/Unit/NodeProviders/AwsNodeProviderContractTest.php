<?php

namespace Tests\Unit\NodeProviders;

use App\Contracts\NodeProvider;
use App\Services\NodeProviders\AwsNodeProvider;
use Tests\Support\NodeProviderContractTestCase;
use Throwable;

class AwsNodeProviderContractTest extends NodeProviderContractTestCase
{
    private string $fakePython;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePython = tempnam(sys_get_temp_dir(), 'xlb-provider-');
        file_put_contents($this->fakePython, $this->fakeRunner([
            'create' => ['state' => 'building', 'resources' => ['InstanceId' => 'i-test-owned']],
            'inspect' => ['state' => 'ready', 'resources' => ['PublicIp' => '192.0.2.10']],
            'probe' => ['verified' => true, 'public_ip' => '192.0.2.10', 'checks' => [['name' => 'outbound', 'passed' => true]]],
            'release' => ['state' => 'released', 'resources' => []],
            'profile' => ['profile' => '{"proxies":[]}'],
        ]));
        chmod($this->fakePython, 0700);
        config([
            'node_automation.profiles.aws-us-west-2-lab-v1.account_id' => '123456789012',
            'node_automation.worker.python' => $this->fakePython,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->fakePython) && is_file($this->fakePython)) {
            unlink($this->fakePython);
        }

        parent::tearDown();
    }

    protected function provider(): NodeProvider
    {
        return app(AwsNodeProvider::class);
    }

    protected function deployment(): object
    {
        return (object) [
            'provider_profile' => 'aws-us-west-2-lab-v1',
            'provider_profile_version' => 1,
            'provider_account_id' => '123456789012',
            'region' => 'us-west-2',
            'instance_type' => 't3.micro',
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
            'resources' => '{}',
            'provider_resource_key' => 'xlb-auto-'.str_repeat('a', 32),
            'stack_name' => 'xlb-auto-'.str_repeat('a', 32),
        ];
    }

    public function test_provider_failure_is_generic_and_does_not_leak_vendor_error_text(): void
    {
        file_put_contents($this->fakePython, $this->fakeRunner([
            'create' => ['error' => 'account-secret-detail'],
        ], 1));

        try {
            $this->provider()->create($this->deployment());
            $this->fail('A failed provider command must throw.');
        } catch (Throwable $exception) {
            $this->assertSame('云服务商步骤失败，请检查授权、节点事件或验证依赖', $exception->getMessage());
            $this->assertStringNotContainsString('account-secret-detail', $exception->getMessage());
        }
    }

    /** @param array<string, array<string, mixed>> $responses */
    private function fakeRunner(array $responses, int $exitCode = 0): string
    {
        return str_replace(
            ['__RESPONSES__', '__EXIT_CODE__'],
            [var_export($responses, true), (string) $exitCode],
            <<<'PHP'
#!/usr/bin/env php
<?php
$request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$responses = __RESPONSES__;
echo json_encode($responses[$request['action']] ?? ['error' => 'missing_fixture'], JSON_THROW_ON_ERROR);
exit(__EXIT_CODE__);
PHP
        );
    }
}
