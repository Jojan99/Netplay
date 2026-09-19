<?php

namespace App\Jobs;

use App\Models\CobranzaCaso;
use App\Services\Cobranza\Asistente;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El cliente le escribió al asistente de cobranza. Corre después de
 * contestarle al webhook (la IA tarda unos segundos) y espera un momento: si
 * el cliente manda varios mensajes seguidos, se responde una sola vez a todos.
 */
class ResponderCobranza
{
    use Dispatchable;

    private const ESPERA = 6;

    public function __construct(private int $casoId, private int $mensajeId, private bool $esperar = true) {}

    public function handle(): void
    {
        if ($this->esperar) {
            sleep((int) config('services.anthropic.espera', self::ESPERA));
        }

        $caso = CobranzaCaso::find($this->casoId);

        if (!$caso || !in_array($caso->estado, CobranzaCaso::CONVERSANDO, true) || !$caso->conversation_id) {
            return;
        }

        // Si llegó otro mensaje después de éste, el trabajo de ése contesta todo.
        $ultimo = DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)
            ->where('sender_type', 'customer')->max('id');

        if ((int) $ultimo !== $this->mensajeId) {
            return;
        }

        $candado = Cache::lock("cobranza:caso:{$caso->id}", 120);

        if (!$candado->get()) {
            return;
        }

        try {
            // Todo lo que escribió desde la última respuesta nuestra.
            $desde = (int) DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)
                ->where('sender_type', '!=', 'customer')->max('id');

            $texto = DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)
                ->where('sender_type', 'customer')->where('id', '>', $desde)->orderBy('id')
                ->get(['message_type', 'content'])
                ->map(fn ($m) => $m->message_type === 'text'
                    ? trim((string) $m->content)
                    : '(envió ' . ([
                        'image' => 'una imagen, puede ser un comprobante de pago', 'audio' => 'un audio que no puedes escuchar',
                        'document' => 'un documento, puede ser un comprobante', 'sticker' => 'un sticker', 'video' => 'un video',
                    ][$m->message_type] ?? 'un archivo') . ')' . (trim((string) $m->content) !== '' ? ' ' . trim((string) $m->content) : ''))
                ->filter()->implode("\n");

            if ($texto === '') {
                return;
            }

            (new Asistente($caso))->responder(mb_substr($texto, 0, 2000));
        } catch (\App\Services\Cobranza\IaOcupada $e) {
            // Pasajero: el mensaje queda sin respuesta y la revisión de cada
            // 5 minutos lo vuelve a intentar.
            Log::info('[Cobranza] IA ocupada, se reintenta en la revisión', ['caso' => $caso->id, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('[Cobranza] El asistente no pudo responder', ['caso' => $caso->id, 'error' => $e->getMessage()]);

            // Sin respuesta automática: que lo vea una persona.
            $caso->fill(['estado' => 'escalado', 'motivo' => mb_substr('El asistente falló al responder: ' . $e->getMessage(), 0, 250), 'visto' => false])->save();
        } finally {
            $candado->release();
        }
    }
}
