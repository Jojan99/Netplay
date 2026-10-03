<?php

namespace App\Console\Commands;

use App\Models\SoporteCaso;
use App\Services\Cobranza\IaOcupada;
use App\Services\Soporte\Conversacion;
use App\Services\Soporte\Herramientas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le contesta a un cliente que le escribió al asistente de soporte.
 *
 * Corre en un proceso aparte (lo lanza el mensaje entrante) porque el
 * diagnóstico habla con la OLT, el router y la IA y tarda: no puede ocupar un
 * proceso de los que atienden la web. Espera un momento por si el cliente
 * manda varios mensajes seguidos, y entonces responde una sola vez a todos.
 */
class SoporteAtender extends Command
{
    protected $signature = 'soporte:atender {caso} {mensaje} {--sin-espera}';
    protected $description = 'El asistente de soporte responde un mensaje de WhatsApp';

    private const ESPERA = 5;

    public function handle(): int
    {
        if (!$this->option('sin-espera')) {
            sleep(self::ESPERA);
        }

        $caso = SoporteCaso::find((int) $this->argument('caso'));

        if (!$caso || !$caso->abierto() || !$caso->conversation_id) {
            return self::SUCCESS;
        }

        // Si llegó otro mensaje después de éste, el proceso de ése contesta todo.
        $ultimo = (int) DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)->where('sender_type', 'customer')->max('id');

        if ($ultimo !== (int) $this->argument('mensaje')) {
            return self::SUCCESS;
        }

        $candado = Cache::store('redis')->lock("soporte:caso:{$caso->id}", 180);

        if (!$candado->get()) {
            return self::SUCCESS;
        }

        try {
            // Todo lo que escribió desde la última respuesta nuestra.
            $desde = (int) DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)->where('sender_type', '!=', 'customer')->max('id');

            $texto = DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)->where('sender_type', 'customer')->where('id', '>', $desde)->orderBy('id')
                ->get(['message_type', 'content'])
                ->map(fn ($m) => $m->message_type === 'text'
                    ? trim((string) $m->content)
                    : '(envió ' . ([
                        'image' => 'una foto que usted no puede ver; puede ser de las luces del equipo', 'audio' => 'un audio que usted no puede escuchar: pídale que lo escriba',
                        'video' => 'un video que usted no puede ver', 'sticker' => 'un sticker', 'document' => 'un documento',
                    ][$m->message_type] ?? 'un archivo') . ')' . (trim((string) $m->content) !== '' ? ' ' . trim((string) $m->content) : ''))
                ->filter()->implode("\n");

            if ($texto === '') {
                return self::SUCCESS;
            }

            (new Conversacion($caso))->responder(mb_substr($texto, 0, 2000));
        } catch (IaOcupada $e) {
            // Pasajero: la revisión de cada minuto lo vuelve a intentar.
            Log::info('[Soporte] IA ocupada, se reintenta', ['caso' => $caso->id]);
        } catch (\Throwable $e) {
            Log::error('[Soporte] El asistente no pudo responder', ['caso' => $caso->id, 'error' => $e->getMessage(), 'en' => basename($e->getFile()) . ':' . $e->getLine()]);

            // Sin respuesta automática: que lo vea una persona, y que el cliente lo sepa.
            try {
                $caso->refresh();
                Herramientas::escalar($caso, mb_substr('El asistente falló al responder: ' . $e->getMessage(), 0, 400));
                (new Conversacion($caso))->enviar('En este momento no puedo revisar su servicio. Le dejo su caso a uno de nuestros asesores, que le sigue atendiendo por este mismo chat.');
            } catch (\Throwable $e2) {
                Log::error('[Soporte] Tampoco se pudo avisar al cliente', ['caso' => $caso->id, 'error' => $e2->getMessage()]);
            }
        } finally {
            $candado->release();
        }

        return self::SUCCESS;
    }
}
