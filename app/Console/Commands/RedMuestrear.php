<?php

namespace App\Console\Commands;

use App\Services\Red\SaludDeLaRed;
use Illuminate\Console\Command;

/**
 * Guarda la última medición de señal de cada OLT para tener historia.
 *
 * No le pregunta nada a las OLT: toma lo que el barrido de alertas ya midió,
 * así que es barato y puede correr seguido.
 */
class RedMuestrear extends Command
{
    protected $signature = 'red:muestrear {--limpiar : Borra además lo más viejo que el período que se conserva}';

    protected $description = 'Anota la señal y el estado de cada puerto PON para ver cómo evoluciona la red';

    public function handle(): int
    {
        $puertos = SaludDeLaRed::muestrear();

        if ($puertos) {
            $this->info("{$puertos} puerto(s) anotados.");
        }

        if ($this->option('limpiar')) {
            $this->info(SaludDeLaRed::limpiar() . ' registro(s) viejos borrados.');
        }

        return self::SUCCESS;
    }
}
