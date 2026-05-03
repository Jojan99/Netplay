<?php

namespace App\Console\Commands;

use App\Services\AutoSuspendService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncArpStatus extends Command
{
    protected $signature   = 'arp:sync {--company= : ID de empresa específica (opcional)}';
    protected $description = 'Sincroniza el estado ARP del MikroTik con el STATUS de la plataforma';

    public function handle(AutoSuspendService $service): int
    {
        $companyId = $this->option('company');

        $query = DB::table('auto_suspend_configs')->where('enabled', true);
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $configs = $query->get();

        if ($configs->isEmpty()) {
            $this->info('Sin empresas con auto-suspensión activa.');
            return 0;
        }

        foreach ($configs as $config) {
            $this->info("Sincronizando empresa {$config->company_id}...");
            $result = $service->syncArpWithPlatform($config->company_id);
            $this->info(
                "Empresa {$config->company_id}: " .
                "habilitados={$result['enabled']} | " .
                "desactivados={$result['disabled']} | " .
                "no encontrados={$result['not_found']} | " .
                "errores={$result['errors']}"
            );
        }

        return 0;
    }
}
