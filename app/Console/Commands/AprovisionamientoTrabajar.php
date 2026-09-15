<?php

namespace App\Console\Commands;

use App\Services\Red\AprovisionamientoDeOnt;
use Illuminate\Console\Command;

/** Aplica su configuración a las ONT recién autorizadas en cuanto aparecen en el TR-069. */
class AprovisionamientoTrabajar extends Command
{
    protected $signature = 'aprovisionamiento:trabajar';

    protected $description = 'Configura WAN, WiFi y cuenta de administración de las ONT recién autorizadas';

    public function handle(): int
    {
        $revisados = AprovisionamientoDeOnt::trabajarPendientes();

        if ($revisados) {
            $this->info("{$revisados} aprovisionamiento(s) revisado(s)");
        }

        return self::SUCCESS;
    }
}
