<?php

namespace App\Console\Commands;

use App\Models\CompanyBillingSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AutoBillingCommand extends Command
{
    protected $signature   = 'billing:auto';
    protected $description = 'Revisa cada hora qué empresas tienen proceso de facturación programado y lo ejecuta';

    public function handle(): int
    {
        $now  = Carbon::now();
        $day  = $now->day;
        $hour = $now->hour;

        // Buscar schedules activos cuyo día y hora coincidan con ahora
        $schedules = CompanyBillingSchedule::with('company')
            ->where('billing_day', $day)
            ->where('billing_hour', $hour)
            ->where('active', true)
            ->whereHas('company', fn($q) => $q->where('active', true))
            ->get();

        if ($schedules->isEmpty()) {
            $this->info("Sin procesos programados para hoy día {$day} a las {$hour}:00.");
            return Command::SUCCESS;
        }

        foreach ($schedules as $schedule) {
            $this->info("Ejecutando: Empresa {$schedule->company_id} | Grupo {$schedule->grupo} | Día factura: {$schedule->billing_day}");

            try {
                Artisan::call('post:create', [
                    'company_id'  => $schedule->company_id,
                    'periodo'     => $schedule->grupo,
                    'billing_day' => $schedule->billing_day,
                ]);

                Log::info('[AUTO_BILLING] Proceso ejecutado', [
                    'company_id'  => $schedule->company_id,
                    'grupo'       => $schedule->grupo,
                    'billing_day' => $schedule->billing_day,
                    'hora'        => $hour,
                ]);

            } catch (\Throwable $e) {
                $this->error("Error empresa {$schedule->company_id} grupo {$schedule->grupo}: {$e->getMessage()}");
                Log::error('[AUTO_BILLING] Error', [
                    'company_id' => $schedule->company_id,
                    'grupo'      => $schedule->grupo,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info('Proceso automático finalizado.');
        return Command::SUCCESS;
    }
}
