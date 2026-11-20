<?php

namespace App\Console\Commands;

use App\Services\ExpiredNodeAccessReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node-access:reconcile-expired')]
#[Description('Queue revocation for node peers whose member has lost access')]
class ReconcileExpiredNodeAccess extends Command
{
    public function handle(ExpiredNodeAccessReconciler $reconciler): int
    {
        $queued = $reconciler->queueRevocations();
        $this->components->info('本轮排入 '.$queued.' 条失效权益撤权任务');

        return self::SUCCESS;
    }
}
