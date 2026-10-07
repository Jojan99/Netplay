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

        // El bot que se pausó para un asesor y nadie volvió a encender.
        $reanudadas = $bot->reanudarPausasOlvidadas();
        if ($reanudadas > 0) {
            $this->info("Se volvió a encender el bot con {$reanudadas} número(s) sin atención de un asesor en 24 h.");
        }

        return self::SUCCESS;
    }
}
