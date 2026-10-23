<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\BandejaDeSalida;
use Illuminate\Console\Command;

/** Manda lo que quedó en la bandeja de salida por las líneas que ya volvieron. */
class WaProcesarSalida extends Command
{
    protected $signature = 'wa:procesar-salida';

    protected $description = 'Envía los mensajes en cola de las líneas de WhatsApp que se reconectaron';

    public function handle(): int
    {
        $r = BandejaDeSalida::procesar();

        if ($r['enviados'] || $r['fallidos']) {
            $this->info("Enviados {$r['enviados']}, fallidos {$r['fallidos']}, esperando {$r['esperando']}.");
        }

        return self::SUCCESS;
    }
}
