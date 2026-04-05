<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ✅ IMPORTANTE: El middleware debe estar en un array
        Broadcast::routes(['middleware' => ['auth:api']]);
        
        require base_path('routes/channels.php');
    }
}