<?php

namespace App\Providers;

use App\Services\SSHConnectionService;
use App\Services\OltManagementService;
use Illuminate\Support\ServiceProvider;

class SSHServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(SSHConnectionService::class, function ($app) {
            return new SSHConnectionService();
        });

        $this->app->singleton(OltManagementService::class, function ($app) {
            return new OltManagementService($app->make(SSHConnectionService::class));
        });
    }

    public function boot()
    {
        //
    }
}