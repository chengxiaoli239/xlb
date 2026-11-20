<?php

namespace App\Console\Commands;

use App\Services\NodePeerProvisioningProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node-access:process-provisioning')]
#[Description('Process due member node peer provisioning requests')]
class ProcessNodePeerProvisioning extends Command
{
    public function handle(NodePeerProvisioningProcessor $processor): int
    {
        $processed = $processor->processDue((int) config('node_access.batch_size', 25));
        $this->info("Processed {$processed} node peer provisioning request(s).");

        return self::SUCCESS;
    }
}
