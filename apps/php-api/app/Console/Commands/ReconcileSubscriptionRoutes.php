<?php

namespace App\Console\Commands;

use App\Services\SubscriptionRouteService;
use Illuminate\Console\Command;

class ReconcileSubscriptionRoutes extends Command
{
    protected $signature = 'subscription-routes:reconcile';

    protected $description = '收口已完成排空窗口的订阅线路版本';

    public function handle(SubscriptionRouteService $routes): int
    {
        $retired = $routes->reconcile();
        $this->info('已收口 '.$retired.' 个线路版本');

        return self::SUCCESS;
    }
}
