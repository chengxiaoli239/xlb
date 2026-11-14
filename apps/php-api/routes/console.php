<?php

use App\Services\DisabledNodePeerRevocationDriver;
use App\Services\NodePeerRevocationDriver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment(['local', 'testing'])) {
    Schedule::command('orders:expire-test')->everyMinute()->withoutOverlapping();
}

$nodeRevocationDriver = app(NodePeerRevocationDriver::class);
if (config('node_access.enabled') && ! ($nodeRevocationDriver instanceof DisabledNodePeerRevocationDriver)) {
    Schedule::command('node-access:process-revocations')->everyMinute()->withoutOverlapping();
}

// Reclamation continues when new provisioning is disabled.
Schedule::command('nodes:reconcile')->everyTenSeconds()->withoutOverlapping(10);
