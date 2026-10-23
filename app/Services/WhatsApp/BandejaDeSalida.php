<?php

namespace App\Services\WhatsApp;

use App\Events\MessageStatusEvent;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que un asesor manda mientras la línea está caída no se pierde: queda en cola y sale solo
 * cuando la línea vuelve.
 *
 * Antes el mensaje quedaba «fallido» y había que acordarse de reenviarlo a mano después; con la
 * línea caída media hora, lo que se le contestó a diez clientes no le llegaba a ninguno. Sólo
 * texto y sólo WhatsApp Web (en Meta no hay «línea caída» que esperar).
 */
class BandejaDeSalida
{
    /** Pasado esto sin poder salir, se da por fallido: el cliente ya no está esperando esa respuesta. */
    private const HORAS_MAXIMAS = 24;

    /** ¿El error es de la línea (caída, sin sesión, el servicio no contesta) y no del mensaje? */
    public static function esDeLaLinea(string $error): bool
    {
        return (bool) preg_match('/no conectad|not connected|503|502|504|conexi[oó]n|timed? ?out|ECONNREFUSED|socket|sesi[oó]n/i', $error);
    }

    public static function encolar(object $conversacion, int $mensajeId, string $error): void
    {
        DB::table('wa_salida')->insertOrIgnore([
            'company_id' => (int) $conversacion->company_id, 'conversation_id' => (int) $conversacion->id,
            'crm_message_id' => $mensajeId, 'wa_linea_id' => isset($conversacion->wa_linea_id) ? (int) $conversacion->wa_linea_id : null,
            'estado' => 'pendiente', 'proximo_intento' => now()->addMinute(), 'error' => mb_substr($error, 0, 250),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{enviados:int, fallidos:int, esperando:int} */
    public static function procesar(int $limite = 50): array
    {
        $r = ['enviados' => 0, 'fallidos' => 0, 'esperando' => 0];

        $pendientes = DB::table('wa_salida')->where('estado', 'pendiente')
            ->where(fn ($q) => $q->whereNull('proximo_intento')->orWhere('proximo_intento', '<=', now()))
            ->orderBy('id')->limit($limite)->get();

        foreach ($pendientes as $p) {
            $msg = DB::table('crm_messages')->where('id', $p->crm_message_id)->first(['id', 'content', 'message_type', 'deleted_at']);
            $conv = DB::table('crm_conversations as c')->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
                ->where('c.id', $p->conversation_id)->first(['c.id', 'c.company_id', 'c.provider', 'c.wa_linea_id', 'cu.phone']);

            // Lo borraron mientras esperaba, o ya no hay a quién mandarlo.
            if (!$msg || $msg->deleted_at || !$conv || trim((string) $msg->content) === '') {
                DB::table('wa_salida')->where('id', $p->id)->update(['estado' => 'fallido', 'error' => 'El mensaje ya no existe', 'updated_at' => now()]);
                continue;
            }

            if (now()->diffInHours($p->created_at) >= self::HORAS_MAXIMAS) {
                self::fallar($p, $conv, 'La línea no volvió en ' . self::HORAS_MAXIMAS . ' horas.');
                $r['fallidos']++;
                continue;
            }

            // Si la línea sigue caída, ni se intenta: se mira otra vez en un minuto.
            $estado = $conv->wa_linea_id ? DB::table('wa_lineas')->where('id', $conv->wa_linea_id)->value('estado') : 'connected';
            if ($estado && $estado !== 'connected') {
                DB::table('wa_salida')->where('id', $p->id)->update(['proximo_intento' => now()->addMinute(), 'updated_at' => now()]);
                $r['esperando']++;
                continue;
            }

            try {
                $res = WhatsAppService::paraConversacion($conv)->mensajeInformativo($conv->phone, (string) $msg->content);
                $falla = !is_array($res) || ($res['success'] ?? true) === false || ($res['status'] ?? null) === 'error';
                if ($falla) {
                    throw new \RuntimeException((string) ($res['error'] ?? $res['message'] ?? 'WhatsApp no lo envió'));
                }
            } catch (\Throwable $e) {
                $intentos = (int) $p->intentos + 1;
                DB::table('wa_salida')->where('id', $p->id)->update([
                    'intentos' => $intentos, 'error' => mb_substr($e->getMessage(), 0, 250),
                    // 1, 2, 4, 8… minutos, hasta media hora entre intentos.
                    'proximo_intento' => now()->addMinutes(min(30, 2 ** min($intentos, 5))), 'updated_at' => now(),
                ]);
                // Un error que no es de la línea no se va a arreglar esperando.
                if (!self::esDeLaLinea($e->getMessage()) && $intentos >= 3) {
                    self::fallar($p, $conv, $e->getMessage());
                    $r['fallidos']++;
                } else {
                    $r['esperando']++;
                }
                continue;
            }

            $externo = $res['messageId'] ?? ($res['messages'][0]['id'] ?? null);
            DB::table('crm_messages')->where('id', $msg->id)->update(array_filter(['status' => 'sent', 'external_id' => $externo], fn ($v) => $v !== null));
            DB::table('wa_salida')->where('id', $p->id)->update(['estado' => 'enviado', 'intentos' => (int) $p->intentos + 1, 'error' => null, 'updated_at' => now()]);
            self::avisar((int) $conv->id, (int) $msg->id, 'sent');
            $r['enviados']++;
        }

        return $r;
    }

    private static function fallar(object $p, object $conv, string $motivo): void
    {
        DB::table('wa_salida')->where('id', $p->id)->update(['estado' => 'fallido', 'error' => mb_substr($motivo, 0, 250), 'updated_at' => now()]);
        DB::table('crm_messages')->where('id', $p->crm_message_id)->update(['status' => 'failed']);
        self::avisar((int) $conv->id, (int) $p->crm_message_id, 'failed');
    }

    private static function avisar(int $conversacion, int $mensaje, string $estado): void
    {
        try {
            broadcast(new MessageStatusEvent($conversacion, $mensaje, $estado));
        } catch (\Throwable $e) {
            Log::info('[Bandeja de salida] No se pudo avisar el estado', ['mensaje' => $mensaje, 'error' => $e->getMessage()]);
        }
    }
}
