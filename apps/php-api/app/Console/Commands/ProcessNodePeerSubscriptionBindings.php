<?php

namespace App\Console\Commands;

use App\Services\NodePeerSubscriptionBindingProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node-access:process-subscription-bindings')]
#[Description('Process due per-device subscription binding requests')]
class ProcessNodePeerSubscriptionBindings extends Command
{
    public function handle(NodePeerSubscriptionBindingProcessor $processor): int
    {
        $processed = $processor->processDue((int) config('node_access.batch_size', 25));
        $this->info("Processed {$processed} node peer subscription binding request(s).");

        return self::SUCCESS;
    }
}
