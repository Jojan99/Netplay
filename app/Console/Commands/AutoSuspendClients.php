<?php

namespace App\Console\Commands;

use App\Services\AutoSuspendService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoSuspendClients extends Command
{
    protected $signature   = 'clients:auto-suspend';
    protected $description = 'Suspende y reactiva clientes según el día de corte configurado por empresa';

    public function handle(AutoSuspendService $service): int
    {
        $today = (int) now()->format('j'); // día del mes sin cero adelante

        $configs = DB::table('auto_suspend_configs')
            ->where('enabled', true)
            ->get();

        $service->logRun('auto-suspend', $configs->count());

        if ($configs->isEmpty()) {
            $this->info('Sin empresas con auto-suspensión activa.');
            return 0;
        }

        foreach ($configs as $config) {
            $suspensionDay = (int) ($config->suspension_day ?? 5);

            if ($today !== $suspensionDay) {
                $this->line("Empresa {$config->company_id}: hoy es día {$today}, día de corte={$suspensionDay}. Omitida.");
                continue;
            }

            $suspended    = $service->suspendOverdue($config->company_id, $config->days_overdue);
            $reactivated  = $service->reactivateAllClear($config->company_id, $config->days_overdue);

            $this->info("Empresa {$config->company_id}: {$suspended} suspendido(s), {$reactivated} reactivado(s).");
        }

        return 0;
    }
}
