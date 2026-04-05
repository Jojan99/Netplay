<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Revisa cada hora qué empresas tienen proceso programado para este día y hora exacta
        $schedule->command('billing:auto')->hourly();

        // Verifica compromisos de pago vencidos y suspende servicio si no pagó
        $schedule->command('commitments:check')->dailyAt('08:00');
        
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
