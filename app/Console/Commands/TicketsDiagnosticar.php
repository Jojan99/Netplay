<?php

namespace App\Console\Commands;

use App\Services\Tickets\DiagnosticoDeTicket;
use Illuminate\Console\Command;

/** Diagnostica la conexión del cliente de un ticket y lo deja como novedad. */
class TicketsDiagnosticar extends Command
{
    protected $signature = 'tickets:diagnosticar {ticket : Id del ticket}';

    protected $description = 'Revisa cuenta, ONT, MikroTik y TR-069 del cliente del ticket y deja la novedad';

    public function handle(): int
    {
        $texto = (new DiagnosticoDeTicket((int) $this->argument('ticket')))->ejecutar();

        $this->line($texto ?? 'Sin diagnóstico: el ticket no tiene un cliente de la empresa.');

        return self::SUCCESS;
    }
}
