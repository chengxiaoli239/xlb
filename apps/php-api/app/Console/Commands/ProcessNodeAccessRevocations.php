<?php

namespace App\Console\Commands;

use App\Services\NodeAccessRevocationProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node-access:process-revocations')]
#[Description('Process due node peer revocations')]
class ProcessNodeAccessRevocations extends Command
{
    public function handle(NodeAccessRevocationProcessor $processor): int
    {
        if (! config('node_access.enabled') || config('node_access.driver') === 'disabled') {
            $this->components->info('节点撤权处理器未启用');

            return self::SUCCESS;
        }

        $processed = $processor->processDue((int) config('node_access.batch_size', 25));
        $this->components->info('本轮处理 '.$processed.' 条撤权任务');

        return self::SUCCESS;
    }
}
