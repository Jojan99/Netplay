<?php

namespace App\Console\Commands;

use App\Models\OltAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Pide a los workers de OLT que terminen para volver con el código nuevo.
 *
 * El worker corre indefinidamente y se queda con el código que cargó al
 * arrancar, así que tras un despliegue sigue ejecutando el anterior. En vez de
 * matarlo —que requiere permisos del usuario del servidor y deja la sesión
 * telnet colgada en la OLT— se le pide que corte solo: cierra su sesión, suelta
 * el lock y el dispatcher lo levanta de nuevo en la próxima consulta.
 */
class OltRecargarWorkers extends Command
{
    protected $signature = 'olt:recargar {olt? : Sólo esta OLT; por defecto todas}';

    protected $description = 'Hace que los workers de OLT se reinicien para tomar el código nuevo';

    public function handle(): int
    {
        $ids = $this->argument('olt')
            ? [(int) $this->argument('olt')]
            : OltAdmin::pluck('id')->all();

        foreach ($ids as $id) {
            try {
                Redis::setex("olt:{$id}:recargar", 120, '1');
                $vivo = Redis::get("olt:{$id}:worker_alive") ? 'corriendo' : 'no está corriendo';
                $this->line("  OLT #{$id}: recarga pedida ({$vivo})");
            } catch (\Throwable $e) {
                $this->error("  OLT #{$id}: {$e->getMessage()}");
            }
        }

        $this->info('Listo. Cada worker termina en su próxima vuelta y vuelve a arrancar solo.');

        return self::SUCCESS;
    }
}
