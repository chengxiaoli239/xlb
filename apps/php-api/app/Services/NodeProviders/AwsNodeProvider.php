<?php

namespace App\Services\NodeProviders;

use App\Contracts\NodeProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class AwsNodeProvider implements NodeProvider
{
    public function resourceKey(string $deploymentKey): string
    {
        if (! preg_match('/^xlb-node-([a-f0-9]{32})$/D', $deploymentKey, $matches)) {
            throw new RuntimeException('节点部署键无效');
        }

        return 'xlb-auto-'.$matches[1];
    }

    public function create(object $deployment): array
    {
        return $this->run('create', $deployment);
    }

    public function inspect(object $deployment): array
    {
        return $this->run('inspect', $deployment);
    }

    public function probe(object $deployment): array
    {
        return $this->run('probe', $deployment);
    }

    public function release(object $deployment): array
    {
        return $this->run('release', $deployment);
    }

    public function profile(object $deployment): array
    {
        return $this->run('profile', $deployment);
    }

    /** @return array<string, mixed> */
    private function run(string $action, object $deployment): array
    {
        $settings = config('node_automation.drivers.aws');
        $env = [];
        foreach (['profile', 'credential_source', 'aws_binary', 'account', 'region', 'vpc', 'subnet', 'image', 'client_cidr', 'instance_profile', 'cleanup_role', 'provision_role'] as $key) {
            $env['XLB_NODE_'.strtoupper($key)] = (string) ($settings[$key] ?? '');
        }
        $env['XLB_NODE_MIHOMO'] = (string) config('node_automation.worker.mihomo');

        $process = new Process([
            config('node_automation.worker.python'), base_path('../../ops/node_manager.py'),
        ], base_path(), $env, json_encode([
            'action' => $action,
            'name' => $deployment->provider_resource_key ?? $deployment->stack_name,
            'instance_type' => $deployment->instance_type,
            'expires_at' => $deployment->expires_at,
            'resources' => json_decode($deployment->resources, true, flags: JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR), 120);
        $process->run();
        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || isset($result['error'])) {
            $safeCode = in_array($result['error'] ?? '', ['dependency_not_found', 'process_timeout', 'configuration_missing', 'dependency_permission_denied', 'aws_request_failed', 'not_ready', 'secret_identity_mismatch', 'expired', 'node_step_failed'], true) ? $result['error'] : 'cloud_step_failed';
            Log::warning('node_provider_step_failed', ['provider' => 'aws', 'action' => $action, 'code' => $safeCode]);
            throw new RuntimeException('云服务商步骤失败，请检查授权、节点事件或验证依赖');
        }

        return $result;
    }
}
