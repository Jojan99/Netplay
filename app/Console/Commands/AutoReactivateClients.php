<?php

namespace App\Console\Commands;

use App\Services\AutoSuspendService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoReactivateClients extends Command
{
    protected $signature   = 'clients:auto-reactivate';
    protected $description = 'Reactiva clientes auto-suspendidos que ya no tienen deuda vencida (corre cada 4 min)';

    public function handle(AutoSuspendService $service): int
    {
        $configs = DB::table('auto_suspend_configs')
            ->where('enabled', true)
            ->get();

        $service->logRun('auto-reactivate', $configs->count());

        if ($configs->isEmpty()) return 0;

        foreach ($configs as $config) {
            $count = $service->reactivateAllClear($config->company_id, $config->days_overdue);
            $this->info("Empresa {$config->company_id}: {$count} cliente(s) reactivado(s).");
        }

        return 0;
    }
}
