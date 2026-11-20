<?php

namespace App\Console\Commands;

use App\Services\NodePeerUsageReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node-access:reconcile-usage')]
#[Description('Reconcile cumulative member peer traffic from node agents')]
class ReconcileNodePeerUsage extends Command
{
    public function handle(NodePeerUsageReconciler $reconciler): int
    {
        $processed = $reconciler->reconcile((int) config('node_access.batch_size', 25));
        $this->info("Reconciled {$processed} node peer usage snapshot(s).");

        return self::SUCCESS;
    }
}
