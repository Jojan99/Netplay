<?php

namespace App\Console\Commands;

use App\Jobs\ResponderCobranza;
use Illuminate\Console\Command;

/**
 * La respuesta del asistente de cobranza, en su propio proceso.
 *
 * Antes corría al final de la petición del webhook (dispatchAfterResponse con la cola en
 * «sync»): la espera de 6 s más la IA ocupaban un proceso del servidor web por respuesta, y
 * en una ráfaga de mensajes se acababan y los webhooks empezaban a fallar. Ahora es como el
 * asistente de soporte: un proceso aparte que se lanza y no frena a nadie.
 */
class CobranzaResponder extends Command
{
    protected $signature = 'cobranza:responder {caso} {mensaje}';

    protected $description = 'Contesta con el asistente de cobranza el mensaje de un cliente';

    public function handle(): int
    {
        (new ResponderCobranza((int) $this->argument('caso'), (int) $this->argument('mensaje')))->handle();

        return self::SUCCESS;
    }

    /** Las pruebas lo apagan para no lanzar procesos de verdad. */
    public static bool $lanzarProcesos = true;

    /** Lo lanza en segundo plano y vuelve enseguida. */
    public static function lanzar(int $casoId, int $mensajeId): void
    {
        if (!self::$lanzarProcesos) {
            return;
        }

        $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf(
            'nohup %s %s cobranza:responder %d %d >> %s 2>&1 &',
            escapeshellarg($php), escapeshellarg(base_path('artisan')), $casoId, $mensajeId,
            escapeshellarg(storage_path('logs/cobranza-asistente.log'))
        ));
    }
}
