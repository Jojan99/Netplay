<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Managers\ConectionRouterManager;
use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Managers\Interfaces\SSHConnectionManagerInterface;
use App\Managers\SSHConnectionManager;

class ManagerServiceProvider extends ServiceProvider
{
    const INTERFACE_POSITION = 0;
    const CLASS_POSITION = 1;

    const SERVICES = [
        [ConectionRouterManagerInterface::class, ConectionRouterManager::class],
        [SSHConnectionManagerInterface::class ,SSHConnectionManager::class]
    ];

    public function register()
    {
        $services = self::SERVICES;
        array_walk($services, function ($value) {
            $this->app->bind($value[self::INTERFACE_POSITION], $value[self::CLASS_POSITION]);
        });
    }

    public function boot()
    {
    }
}
