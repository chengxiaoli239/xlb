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
        $nodes = DB::table('nodes')->where('status', 'available')->when(
            $requestedNodeIds !== [],
            fn ($query) => $query->whereIn('id', $requestedNodeIds),
        )->orderBy('id')->limit(max(1, (int) config('node_health_probe.batch_size', 25)))->pluck('id');

        if ($nodes->isEmpty()) {
            $this->components->info('没有可探测的可用节点。');

            return self::SUCCESS;
        }

        $coordinator = new NodeHealthProbeCoordinator(
            app(NodeHealthProbeDriver::class),
            new NodeHealthCircuitBreaker,
            new DatabaseNodeHealthStateStore,
        );
        $assessments = (new NodeHealthProbeBatchRunner($coordinator))->run($nodes);
        $succeeded = count(array_filter($assessments, static fn ($assessment): bool => $assessment->result->succeeded));
        $failed = count($assessments) - $succeeded;

        $this->components->info(
            "已保存 {$succeeded} 条探测成功和 {$failed} 条探测失败的内部状态；这不构成节点连接或会员隧道验收。",
        );

        return self::SUCCESS;
    }
}
