<?php

namespace App\Console\Commands;

use App\Services\Olt\AltasPendientes;
use App\UseCases\OltAdmin\OltAdminUseCase;
use Illuminate\Console\Command;

/** Termina las altas de ONT que la OLT completó después de que el panel dejó de esperar. */
class OltCompletarAltas extends Command
{
    protected $signature = 'olt:completar-altas';

    protected $description = 'Vincula y aprovisiona las ONT cuya alta se cortó por tiempo y la OLT terminó después';

    public function handle(OltAdminUseCase $olts): int
    {
        if (!AltasPendientes::todas()) {
            return self::SUCCESS;
        }

        foreach (AltasPendientes::completar($olts) as $linea) {
            $this->line($linea);
        }

        return self::SUCCESS;
    }
}
