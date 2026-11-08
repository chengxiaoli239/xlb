<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NodeAutomation
{
    public function __construct(private NodeProviderRegistry $providers) {}

    /** @param array{name: string, request_id: string, instance_type: string, lifetime_minutes: int} $input */
    public function create(array $input, string $actor): object
    {
        abort_unless(config('node_automation.enabled'), 409, '云节点自动化尚未启用');

        return Cache::lock('node-automation', 600)->block(3, function () use ($input, $actor): object {
            $existing = DB::table('node_deployments')->where('request_id', $input['request_id'])->first();
            if ($existing) {
                $nodeName = DB::table('nodes')->where('id', $existing->node_id)->value('name');
                abort_if($existing->actor_user_id !== $actor || $existing->instance_type !== $input['instance_type'] || $nodeName !== trim($input['name']), 409, '请求标识已被另一项部署使用');

                return $existing;
            }
            abort_if(DB::table('node_deployments')->where('phase', '!=', 'released')->count() >= config('node_automation.max_nodes'), 409, '已达到测试节点上限，请等待资源释放');

            return DB::transaction(function () use ($input, $actor): object {
                $providerKey = (string) config('node_automation.default_provider', 'aws');
                $provider = $this->providers->resolve($providerKey);
                $id = (string) Str::uuid();
                $nodeId = (string) Str::uuid();
                $deploymentKey = 'xlb-node-'.str_replace('-', '', $id);
                $providerResourceKey = $provider->resourceKey($deploymentKey);
                $now = now()->toIso8601String();
                DB::table('nodes')->insert([
                    'id' => $nodeId, 'name' => trim($input['name']), 'region' => config('node_automation.drivers.'.$providerKey.'.region_label'),
                    'protocol' => 'SS2022', 'endpoint_host' => 'pending.invalid', 'endpoint_port' => 18443,
                    'status' => 'candidate', 'is_test' => true, 'max_sessions' => 0, 'active_sessions' => 0,
                    'latency_ms' => null, 'health_status' => 'pending', 'last_health_check_at' => null,
                    'config_version' => 1, 'updated_at' => $now,
                ]);
                DB::table('node_deployments')->insert([
                    'id' => $id, 'request_id' => $input['request_id'], 'node_id' => $nodeId, 'actor_user_id' => $actor,
                    'provider' => $providerKey, 'deployment_key' => $deploymentKey,
                    'provider_resource_key' => $providerResourceKey, 'stack_name' => $providerResourceKey,
                    'region' => config('node_automation.drivers.'.$providerKey.'.region'),
                    'instance_type' => $input['instance_type'], 'phase' => 'queued',
                    'expires_at' => now()->addMinutes($input['lifetime_minutes'])->toIso8601String(),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->event($id, 'queued', '已排队：通过 '.strtoupper($providerKey).' 创建独立测试服务器，验证通过后可下载管理员测试配置');
                $this->audit($id, $actor, 'node.deployment_requested');

                return $this->find($id);
            });
        });
    }

    public function retire(string $id, string $actor): object
    {
        return Cache::lock('node-automation', 600)->block(3, function () use ($id, $actor): object {
            $row = $this->find($id);
            if ($row->phase === 'released' || $row->release_requested_at) {
                return $row;
            }
            $this->requestRelease($row, '管理员申请永久下架；停止提供测试配置，等待排空后释放整栈');
            $this->audit($id, $actor, 'node.release_requested');

            return $this->find($id);
        });
    }

    public function reconcile(): int
    {
        $lock = Cache::lock('node-automation', 600);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $rows = DB::table('node_deployments')->where('phase', '!=', 'released')->orderBy('created_at')->get();
            foreach ($rows as $row) {
                if (now()->greaterThanOrEqualTo($row->expires_at) && ! $row->release_requested_at) {
                    $this->requestRelease($row, '有效期已到，回收测试节点');
                    $row = $this->find($row->id);
                }
                if ($row->next_attempt_at && now()->lessThan($row->next_attempt_at)) {
                    continue;
                }
                try {
                    $this->step($row);
                } catch (Throwable $error) {
                    report(new RuntimeException('测试节点服务商步骤失败：'.$row->id));
                    $attempts = $row->attempts + 1;
                    $retry = min(300, 10 * (2 ** min($attempts, 5)));
                    $this->update($row->id, [
                        'attempts' => $attempts, 'last_error' => '云端步骤失败；请核查服务商身份、资源事件及来源 IP。系统将重试回收。',
                        'next_attempt_at' => now()->addSeconds($retry)->toIso8601String(),
                    ]);
                    $this->event($row->id, $row->phase, '步骤失败，已安排重试（第 '.$attempts.' 次）');
                    if (! in_array($row->phase, ['draining', 'releasing'], true) && ($row->phase === 'queued' || $attempts >= 5)) {
                        $this->requestRelease($this->find($row->id), '部署未通过验收，自动进入资源回收');
                    }
                }
            }

            return $rows->count();
        } finally {
            $lock->release();
        }
    }

    private function step(object $row): void
    {
        if ($row->phase === 'draining') {
            if (now()->lessThan($row->release_not_before)) {
                return;
            }
            $this->transition($row, 'releasing', '开始释放服务器、磁盘、安全组、测试凭据及到期任务');

            return;
        }
        if ($row->phase === 'releasing') {
            $result = $this->providers->resolve($row->provider)->release($row);
            $this->saveResources($row, $result);
            if (($result['state'] ?? '') === 'released') {
                $this->markReleased($row);
            }

            return;
        }
        if ($row->phase === 'queued') {
            $result = $this->providers->resolve($row->provider)->create($row);
            $this->saveResources($row, $result);
            $this->transition($row, 'provisioning', strtoupper($row->provider).' 已受理创建；独立服务器正在准备');

            return;
        }
        if ($row->phase === 'verifying') {
            $result = $this->providers->resolve($row->provider)->probe($row);
            if (($result['verified'] ?? false) !== true) {
                throw new RuntimeException('协议连接未通过');
            }
            $this->transition($row, 'available', 'Mihomo 经公网节点完成 HTTPS 与出口 IP 校验；仅管理员测试可用', ['verified_at' => now()->toIso8601String()]);
            DB::table('nodes')->where('id', $row->node_id)->update([
                'status' => 'available', 'endpoint_host' => $result['public_ip'], 'health_status' => 'verified_test',
                'last_health_check_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]);

            return;
        }
        $result = $this->providers->resolve($row->provider)->inspect($row);
        $this->saveResources($row, $result);
        $state = $result['state'] ?? '';
        if ($state === 'released') {
            $this->markReleased($row);

            return;
        }
        if ($row->phase !== 'available' && now()->greaterThan(CarbonImmutable::parse($row->created_at)->addMinutes(6)) && $state !== 'ready') {
            $this->requestRelease($row, '自动部署超过 6 分钟未就绪，停止等待并回收资源');

            return;
        }
        if ($state === 'deleting' || $state === 'failed') {
            $this->requestRelease($row, '云端已到期、删除或部署失败，开始核对资源回收');
        } elseif ($state === 'ready' && $row->phase !== 'available') {
            $this->transition($row, 'verifying', '服务端口已就绪，等待真实协议与出网验证');
        } elseif ($state === 'bootstrapping' && $row->phase === 'provisioning') {
            $this->transition($row, 'bootstrapping', '服务器已创建，正在自动安装及启动节点服务');
        } elseif ($row->phase === 'available' && $state !== 'ready') {
            $this->requestRelease($row, '已验收测试端口失联，撤下测试配置并回收资源');
        } else {
            $this->update($row->id, ['next_attempt_at' => now()->addSeconds($row->phase === 'available' ? 60 : 10)->toIso8601String()]);
        }
    }

    private function markReleased(object $row): void
    {
        DB::transaction(function () use ($row): void {
            $this->transition($row, 'released', '云端已核对：本节点资源全部释放', ['released_at' => now()->toIso8601String()]);
            DB::table('nodes')->where('id', $row->node_id)->update(['status' => 'retired', 'health_status' => 'released', 'updated_at' => now()->toIso8601String()]);
        });
    }

    private function requestRelease(object $row, string $message): void
    {
        $this->transition($row, 'draining', $message, [
            'release_requested_at' => now()->toIso8601String(),
            'release_not_before' => now()->addSeconds(config('node_automation.drain_seconds'))->toIso8601String(),
        ]);
        DB::table('nodes')->where('id', $row->node_id)->update(['status' => 'draining', 'updated_at' => now()->toIso8601String()]);
    }

    /** @param array<string, mixed> $result */
    private function saveResources(object $row, array $result): void
    {
        if (isset($result['resources'])) {
            $old = json_decode($row->resources, true, flags: JSON_THROW_ON_ERROR);
            $this->update($row->id, ['resources' => json_encode(array_replace($old, $result['resources']), JSON_THROW_ON_ERROR)]);
        }
    }

    /** @param array<string, mixed> $extra */
    private function transition(object $row, string $phase, string $message, array $extra = []): void
    {
        DB::transaction(function () use ($row, $phase, $message, $extra): void {
            $this->update($row->id, ['phase' => $phase, 'attempts' => 0, 'last_error' => null, 'next_attempt_at' => null, ...$extra]);
            if ($row->phase !== $phase) {
                $this->event($row->id, $phase, $message);
            }
        });
    }

    /** @param array<string, mixed> $values */
    private function update(string $id, array $values): void
    {
        DB::table('node_deployments')->where('id', $id)->update([...$values, 'updated_at' => now()->toIso8601String()]);
    }

    private function event(string $id, string $phase, string $message): void
    {
        DB::table('node_deployment_events')->insert(['id' => (string) Str::uuid(), 'deployment_id' => $id, 'phase' => $phase, 'message' => $message, 'created_at' => now()->toIso8601String()]);
    }

    private function audit(string $id, string $actor, string $action): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $actor, 'action' => $action, 'target_type' => 'node_deployment', 'target_id' => $id, 'created_at' => now()->toIso8601String()]);
    }

    public function find(string $id): object
    {
        $row = DB::table('node_deployments')->where('id', $id)->first();
        abort_unless($row, 404, '云节点任务不存在');

        return $row;
    }
}
