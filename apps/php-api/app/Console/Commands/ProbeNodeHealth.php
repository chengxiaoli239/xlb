<?php

namespace App\Console\Commands;

use App\Services\DatabaseNodeHealthStateStore;
use App\Services\NodeHealthCircuitBreaker;
use App\Services\NodeHealthProbeBatchRunner;
use App\Services\NodeHealthProbeCoordinator;
use App\Services\NodeHealthProbeDriver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('nodes:probe-health {--node=* : 仅探测指定的可用节点 ID}')]
#[Description('采集并持久化内部节点探测状态；不代表会员链路验收')]
class ProbeNodeHealth extends Command
{
    public function handle(): int
    {
        if (! config('node_health_probe.enabled')) {
            $this->components->info('节点健康探测未启用；未访问节点。');

            return self::SUCCESS;
        }

        if (! app()->bound(NodeHealthProbeDriver::class)) {
            $this->components->error('节点健康探测已配置启用，但未配置探测驱动。');

            return self::FAILURE;
        }

        $requestedNodeIds = array_values(array_unique(array_map('strval', (array) $this->option('node'))));
        $batchSize = max(1, (int) config('node_health_probe.batch_size', 25));
        if ($requestedNodeIds !== [] && count($requestedNodeIds) > $batchSize) {
            $this->components->error(
                '显式指定的节点数超过单批上限，请拆分为多个较小批次。',
            );

            return self::FAILURE;
        }

        $nodes = DB::table('nodes')->leftJoin(
            'node_health_probe_attempts as probe_attempt',
            'probe_attempt.node_id',
            '=',
            'nodes.id',
        )->where('nodes.status', 'available')->when(
            $requestedNodeIds !== [],
            fn ($query) => $query->whereIn('nodes.id', $requestedNodeIds),
        )->select('nodes.id')->orderByRaw('CASE WHEN probe_attempt.last_attempted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('probe_attempt.last_attempted_at')->orderBy('nodes.id')->limit($batchSize)->pluck('nodes.id');

        if ($nodes->isEmpty()) {
            $this->components->info('没有可探测的可用节点。');

            return self::SUCCESS;
        }

        $coordinator = new NodeHealthProbeCoordinator(
            app(NodeHealthProbeDriver::class),
            new NodeHealthCircuitBreaker,
            new DatabaseNodeHealthStateStore,
        );
        $assessments = (new NodeHealthProbeBatchRunner($coordinator))->run(
            $nodes,
            static function (string $nodeId): void {
                DB::table('node_health_probe_attempts')->updateOrInsert(
                    ['node_id' => $nodeId],
                    ['last_attempted_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')],
                );
            },
        );
        $succeeded = count(array_filter($assessments, static fn ($assessment): bool => $assessment->result->succeeded));
        $failed = count($assessments) - $succeeded;

        $this->components->info(
            "已保存 {$succeeded} 条探测成功和 {$failed} 条探测失败的内部状态；这不构成节点连接或会员隧道验收。",
        );

        return self::SUCCESS;
    }
}
