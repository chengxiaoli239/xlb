<?php

namespace App\Services;

use App\Contracts\NodeProvider;
use RuntimeException;

class NodeProviderRegistry
{
    public function resolve(string $key): NodeProvider
    {
        if (! preg_match('/^[a-z0-9_-]{1,32}$/D', $key)) {
            throw new RuntimeException('节点服务商标识无效');
        }

        $class = config('node_automation.drivers.'.$key.'.class');
        if (! is_string($class) || ! is_a($class, NodeProvider::class, true)) {
            throw new RuntimeException('节点服务商驱动未配置：'.$key);
        }

        return app($class);
    }

    /** @return array<string, mixed> */
    public function profile(string $profileKey, bool $requireEnabled = true): array
    {
        if (! preg_match('/^[a-z0-9_-]{1,96}$/D', $profileKey)) {
            throw new RuntimeException('节点部署档案标识无效');
        }

        $profile = config('node_automation.profiles.'.$profileKey);
        if (! is_array($profile)) {
            throw new RuntimeException('节点部署档案未配置');
        }
        if ($requireEnabled && ($profile['enabled'] ?? false) !== true) {
            throw new RuntimeException('节点部署档案未启用');
        }

        $provider = $profile['provider'] ?? null;
        if (! is_string($provider)) {
            throw new RuntimeException('节点部署档案缺少服务商标识');
        }
        $driver = config('node_automation.drivers.'.$provider);
        $class = is_array($driver) ? ($driver['class'] ?? null) : null;
        if (! is_string($class) || ! is_a($class, NodeProvider::class, true)) {
            throw new RuntimeException('节点服务商驱动未配置：'.$provider);
        }

        $instanceTypes = $profile['instance_types'] ?? null;
        if (! is_array($instanceTypes) || $instanceTypes === []
            || ! is_string($profile['region'] ?? null)
            || ! is_int($profile['version'] ?? null) || $profile['version'] < 1) {
            throw new RuntimeException('节点部署档案配置不完整');
        }

        return ['id' => $profileKey, ...$profile];
    }

    /** @return array<int, array{id: string, provider: string, version: int, label: string, region: string, region_label: string, instance_types: array<string, string>, lifetime_minutes: array<int, int>}> */
    public function availableProfiles(): array
    {
        $profiles = [];
        foreach (array_keys(config('node_automation.profiles', [])) as $profileKey) {
            try {
                $profile = $this->profile((string) $profileKey);
            } catch (RuntimeException) {
                continue;
            }

            $profiles[] = [
                'id' => $profile['id'],
                'provider' => $profile['provider'],
                'version' => $profile['version'],
                'label' => $profile['label'] ?? strtoupper($profile['provider']),
                'region' => $profile['region'],
                'region_label' => $profile['region_label'] ?? $profile['region'],
                'instance_types' => $profile['instance_types'],
                'lifetime_minutes' => $profile['lifetime_minutes'] ?? [],
            ];
        }

        return $profiles;
    }

    public function resolveDeployment(object $deployment): NodeProvider
    {
        $profile = $this->profile((string) ($deployment->provider_profile ?? ''), false);
        $storedAccountId = (string) ($deployment->provider_account_id ?? '');
        if (($deployment->provider ?? null) !== $profile['provider']
            || (int) ($deployment->provider_profile_version ?? 0) !== $profile['version']
            || ($deployment->region ?? null) !== $profile['region']
            || $storedAccountId !== (string) ($profile['account_id'] ?? '')) {
            throw new RuntimeException('部署目标与固定服务商档案不匹配，已拒绝云端操作');
        }

        return $this->resolve($profile['provider']);
    }
}
