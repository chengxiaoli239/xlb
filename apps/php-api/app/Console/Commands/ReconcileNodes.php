<?php

namespace App\Console\Commands;

use App\Services\NodeAutomation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nodes:reconcile')]
#[Description('推进专用测试节点部署、协议验证和资源回收')]
class ReconcileNodes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NodeAutomation $automation): int
    {
        $this->info('已检查 '.$automation->reconcile().' 个未完成节点任务');

        return self::SUCCESS;
    }
}
