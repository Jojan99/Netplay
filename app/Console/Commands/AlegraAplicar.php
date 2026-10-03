<?php

namespace App\Console\Commands;

use App\Services\Alegra\OperacionesDeAlegra;
use Illuminate\Console\Command;

/**
 * Ejecuta en Alegra lo que ya fue APROBADO en la bandeja de una empresa.
 *
 * Lo lanza el panel en segundo plano cuando alguien aprueba (y la sincronización, para lo que
 * está en automático). No aprueba nada por su cuenta: sólo toma lo que quedó «aprobada».
 */
class AlegraAplicar extends Command
{
    protected $signature = 'alegra:aplicar {empresa : Id de la empresa}';

    protected $description = 'Escribe en Alegra las operaciones ya aprobadas en la bandeja';

    public function handle(): int
    {
        $r = (new OperacionesDeAlegra((int) $this->argument('empresa')))->aplicarAprobadas();

        $this->line("Hechas: {$r['hechas']} · fallidas: {$r['fallidas']}");

        return self::SUCCESS;
    }
}
