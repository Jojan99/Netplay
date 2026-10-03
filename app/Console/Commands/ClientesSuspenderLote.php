<?php

namespace App\Console\Commands;

use App\Services\Clientes\SuspensionMasiva;
use Illuminate\Console\Command;

/** Trabaja una orden de suspensión masiva: la lanza la pantalla y corre aparte de la web. */
class ClientesSuspenderLote extends Command
{
    protected $signature = 'clientes:suspender-lote {lote}';
    protected $description = 'Ejecuta una orden de suspensión masiva (suspender y/o avisar a los clientes elegidos)';

    public function handle(): int
    {
        SuspensionMasiva::procesar((int) $this->argument('lote'));

        return self::SUCCESS;
    }
}
