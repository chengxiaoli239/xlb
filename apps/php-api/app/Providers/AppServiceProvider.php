<?php

namespace App\Providers;

use App\Services\DisabledNodePeerRevocationDriver;
use App\Services\NodePeerRevocationDriver;
use App\Services\SandboxNodePeerRevocationDriver;
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

            return $app->make(DisabledNodePeerRevocationDriver::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
