<?php

use App\Services\DisabledNodePeerProvisioningDriver;
use App\Services\DisabledNodePeerRevocationDriver;
use App\Services\DisabledNodePeerUsageDriver;
use App\Services\NodePeerProvisioningDriver;
use App\Services\NodePeerRevocationDriver;
use App\Services\NodePeerSubscriptionBindingDriver;
use App\Services\NodePeerUsageDriver;
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

$nodeProvisioningDriver = app(NodePeerProvisioningDriver::class);
if (config('node_access.provisioning_enabled') && ! ($nodeProvisioningDriver instanceof DisabledNodePeerProvisioningDriver)) {
    Schedule::command('node-access:process-provisioning')->everyMinute()->withoutOverlapping();
}

$subscriptionBindingDriver = app(NodePeerSubscriptionBindingDriver::class);
if (config('node_access.subscription_binding_enabled')
    && ! ($subscriptionBindingDriver instanceof DisabledNodePeerSubscriptionBindingDriver)) {
    Schedule::command('node-access:process-subscription-bindings')->everyMinute()->withoutOverlapping();
}

$nodeUsageDriver = app(NodePeerUsageDriver::class);
if (config('node_access.usage_reconciliation_enabled') && ! ($nodeUsageDriver instanceof DisabledNodePeerUsageDriver)) {
    Schedule::command('node-access:reconcile-usage')->everyMinute()->withoutOverlapping();
}

// Access-loss reconciliation continues even when the node driver is unavailable.
Schedule::command('node-access:reconcile-expired')->everyMinute()->withoutOverlapping();
// Reclamation continues when new provisioning is disabled.
Schedule::command('nodes:reconcile')->everyTenSeconds()->withoutOverlapping(10);
Schedule::command('subscription-routes:reconcile')->everyMinute()->withoutOverlapping(60);
