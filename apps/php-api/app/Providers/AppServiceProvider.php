<?php

namespace App\Providers;

use App\Services\ConfiguredNodeDataPlaneProbeTransport;
use App\Services\DisabledNodePeerProvisioningDriver;
use App\Services\DisabledNodePeerRevocationDriver;
use App\Services\DisabledNodePeerSubscriptionBindingDriver;
use App\Services\DisabledNodePeerUsageDriver;
use App\Services\HttpNodePeerProvisioningDriver;
use App\Services\HttpNodePeerRevocationDriver;
use App\Services\HttpNodePeerSubscriptionBindingDriver;
use App\Services\HttpNodePeerUsageDriver;
use App\Services\MtlsNodeHealthProbeDriver;
use App\Services\NodeDataPlaneProbeFailure;
use App\Services\NodeDataPlaneProbeTransportException;
use App\Services\NodeHealthProbeDriver;
use App\Services\NodePeerProvisioningDriver;
use App\Services\NodePeerRevocationDriver;
use App\Services\NodePeerSubscriptionBindingDriver;
use App\Services\NodePeerUsageDriver;
use App\Services\SandboxNodePeerProvisioningDriver;
use App\Services\SandboxNodePeerRevocationDriver;
use App\Services\SandboxNodePeerSubscriptionBindingDriver;
use Illuminate\Contracts\Foundation\Application as LaravelApplication;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NodePeerRevocationDriver::class, function (LaravelApplication $app): NodePeerRevocationDriver {
            if ($app->environment(['local', 'testing']) && config('node_access.driver') === 'sandbox') {
                return $app->make(SandboxNodePeerRevocationDriver::class);
            }
            if (config('node_access.enabled') && config('node_access.driver') === 'agent') {
                return $app->make(HttpNodePeerRevocationDriver::class);
            }

            return $app->make(DisabledNodePeerRevocationDriver::class);
        });
        $this->app->bind(NodePeerProvisioningDriver::class, function (LaravelApplication $app): NodePeerProvisioningDriver {
            if ($app->environment(['local', 'testing']) && config('node_access.driver') === 'sandbox') {
                return $app->make(SandboxNodePeerProvisioningDriver::class);
            }
            if (config('node_access.provisioning_enabled') && config('node_access.driver') === 'agent') {
                return $app->make(HttpNodePeerProvisioningDriver::class);
            }

            return $app->make(DisabledNodePeerProvisioningDriver::class);
        });
        $this->app->bind(NodePeerSubscriptionBindingDriver::class, function (LaravelApplication $app): NodePeerSubscriptionBindingDriver {
            if ($app->environment(['local', 'testing']) && config('node_access.driver') === 'sandbox') {
                return $app->make(SandboxNodePeerSubscriptionBindingDriver::class);
            }
            if (config('node_access.subscription_binding_enabled') && config('node_access.driver') === 'agent') {
                return $app->make(HttpNodePeerSubscriptionBindingDriver::class);
            }

            return $app->make(DisabledNodePeerSubscriptionBindingDriver::class);
        });
        $this->app->bind(NodePeerUsageDriver::class, function (LaravelApplication $app): NodePeerUsageDriver {
            if (config('node_access.usage_reconciliation_enabled') && config('node_access.driver') === 'agent') {
                return $app->make(HttpNodePeerUsageDriver::class);
            }

            return $app->make(DisabledNodePeerUsageDriver::class);
        });

        if (config('node_health_probe.transport') === 'http') {
            $this->app->bind(NodeHealthProbeDriver::class, function (): NodeHealthProbeDriver {
                $endpoints = config('node_health_probe.endpoints');
                if (! is_array($endpoints)) {
                    throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED);
                }
                $transport = new ConfiguredNodeDataPlaneProbeTransport(
                    $endpoints,
                    config('node_health_probe.client_certificate'),
                    config('node_health_probe.client_key'),
                );

                return new MtlsNodeHealthProbeDriver(
                    $transport,
                    max(1, min(30_000, (int) config('node_health_probe.timeout_ms', 5_000))),
                );
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
