<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment(['local', 'testing'])) {
    Schedule::command('orders:expire-test')->everyMinute()->withoutOverlapping();
}

// Reclamation continues when new provisioning is disabled.
Schedule::command('nodes:reconcile')->everyTenSeconds()->withoutOverlapping(10);
