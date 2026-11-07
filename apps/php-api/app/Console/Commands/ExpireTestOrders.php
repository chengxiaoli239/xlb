<?php

namespace App\Console\Commands;

use App\Services\TestOrderLifecycle;
use Illuminate\Console\Command;

class ExpireTestOrders extends Command
{
    protected $signature = 'orders:expire-test';

    protected $description = 'Expire overdue local test orders without changing entitlements';

    public function handle(TestOrderLifecycle $lifecycle): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Test order expiry is only available in local/testing environments.');

            return self::FAILURE;
        }
        $this->info('Expired test orders: '.$lifecycle->expireDue());

        return self::SUCCESS;
    }
}
