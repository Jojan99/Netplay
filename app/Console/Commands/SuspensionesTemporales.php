<?php

namespace App\Console\Commands;

use App\Services\Clientes\SuspensionTemporal;
use Illuminate\Console\Command;

/**
 * Empieza las suspensiones temporales que tocan, reactiva a quien vuelve al día y
 * deja «requiere atención» a quien vuelve debiendo (eso abre una alerta crítica).
 */
class SuspensionesTemporales extends Command
{
    protected $signature = 'clientes:suspensiones-temporales';

    protected $description = 'Aplica y termina las suspensiones temporales que pidieron los clientes';

    public function handle(SuspensionTemporal $suspensiones): int
    {
        $r = $suspensiones->revisar();
        $this->line("Iniciadas: {$r['iniciadas']} | reactivadas: {$r['terminadas']} | con deuda (requieren atención): {$r['con_deuda']}");

        return self::SUCCESS;
    }
}
