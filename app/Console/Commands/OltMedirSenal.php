<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use App\Services\Olt\SenalDeLaOlt;
use Illuminate\Console\Command;

/**
 * Mide la señal óptica de toda una OLT y la deja guardada.
 *
 * El barrido de la tabla óptica tarda más de un minuto en una OLT con cientos
 * de ONT (la OLT le pregunta a cada una), así que no puede hacerse dentro de
 * una petición web: la pantalla lanza esto y muestra la última medición.
 */
class OltMedirSenal extends Command
{
    protected $signature = 'olt:medir-senal {olt : Id de la OLT}';

    protected $description = 'Mide la señal óptica de todas las ONT de una OLT y la guarda';

    public function handle(): int
    {
        $olt = OltAdmin::find((int) $this->argument('olt'));

        if (!$olt) {
            $this->error('No existe esa OLT.');
            return self::FAILURE;
        }

        // La pidió el operador desde la pantalla: la puede cancelar.
        $r = SenalDeLaOlt::medirAhora($olt, true);

        $this->line("OLT {$olt->id}: " . count($r['onts'] ?? []) . ' ONT medidas' . ($r['error'] ? " · {$r['error']}" : ''));

        return self::SUCCESS;
    }
}
