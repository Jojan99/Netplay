<?php

namespace App\Console\Commands;

use App\Services\WaBotService;
use Illuminate\Console\Command;

/**
 * Cierra las conversaciones del bot que quedaron esperando respuesta.
 *
 * Sin esto la sesión simplemente vence en silencio: el cliente nunca se entera
 * de que la conversación se cerró, y la próxima vez que escribe le sale el menú
 * de nuevo sin explicación.
 */
class CerrarSesionesInactivas extends Command
{
    protected $signature = 'wa:cerrar-sesiones-inactivas
                            {--minutos=30 : No avisar de sesiones vencidas hace más de esto}';

    protected $description = 'Avisa y cierra las conversaciones del bot sin respuesta del cliente';

    public function handle(WaBotService $bot): int
    {
        $cerradas = $bot->closeIdleSessions((int) $this->option('minutos'));

        $this->info($cerradas === 0
            ? 'No había conversaciones que cerrar.'
            : "Se cerraron {$cerradas} conversación(es) por inactividad.");

        return self::SUCCESS;
    }
}
