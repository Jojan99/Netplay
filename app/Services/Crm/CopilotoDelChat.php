<?php

namespace App\Services\Crm;

use App\Services\Cobranza\Ia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El copiloto del asesor en el chat: le redacta una respuesta para que la revise y la mande,
 * o le resume la conversación en tres líneas (al tomarla del bot o de otro asesor).
 *
 * Usa la misma IA que el asistente de cobranza (la clave de la empresa o la de Netvula) y lo que
 * el asesor ya ve en la ficha: plan, deuda, estado del servicio y ticket abierto. Nunca manda
 * nada solo: lo que devuelve va a la caja de texto del asesor.
 */
class CopilotoDelChat
{
    private const MENSAJES = 25;

    public function __construct(private int $companyId) {}

    public static function disponible(int $companyId): bool
    {
        return Ia::disponible($companyId);
    }

    /** @param array<string,mixed> $ficha el resumen del cliente (customer-summary) */
    public function sugerir(int $conversationId, array $ficha, ?string $asesor = null): string
    {
        $empresa = (string) (DB::table('companies')->where('id', $this->companyId)->value('name') ?: 'la empresa');

        $sistema = "Eres el copiloto de un asesor de atención al cliente de {$empresa}, un proveedor de internet por fibra en Colombia. "
            . 'Redacta UNA respuesta para que el asesor la envíe por WhatsApp al cliente. '
            . "Reglas:\n"
            . "- Español de Colombia, trato de «usted», cordial y breve (máximo 3 oraciones, sin listas).\n"
            . "- Responde a lo último que escribió el cliente, usando la conversación y la ficha.\n"
            . "- No inventes datos: montos, fechas, planes o estados sólo si están en la ficha. Si falta un dato, pídelo.\n"
            . "- No prometas horas exactas de visita ni de arreglo.\n"
            . "- Si el cliente reporta falla y hay un ticket abierto, menciónalo por su número.\n"
            . '- Devuelve sólo el texto del mensaje, sin comillas ni explicaciones.'
            . ($asesor ? "\nEl asesor se llama {$asesor}; no hace falta presentarse si ya lo hizo." : '');

        return $this->pedir($sistema, $conversationId, $ficha, 'Redacta la respuesta del asesor al último mensaje del cliente.', 350);
    }

    /** @param array<string,mixed> $ficha */
    public function resumir(int $conversationId, array $ficha): string
    {
        $sistema = 'Resumes conversaciones de WhatsApp de un proveedor de internet para el asesor que la toma. '
            . "Exactamente tres líneas, cada una empezando así:\n"
            . "«Pide:» lo que necesita el cliente.\n«Hecho:» lo que ya se hizo o se le dijo (bot, asistente o asesor).\n«Falta:» lo que queda pendiente.\n"
            . 'Breve, concreto, en español, sin inventar.';

        return $this->pedir($sistema, $conversationId, $ficha, 'Resume esta conversación.', 300);
    }

    private function pedir(string $sistema, int $conversationId, array $ficha, string $instruccion, int $maxTokens): string
    {
        $mensajes = DB::table('crm_messages')->where('conversation_id', $conversationId)
            ->where(fn ($q) => $q->whereNull('is_note')->orWhere('is_note', 0))
            ->orderByDesc('id')->limit(self::MENSAJES)->get(['sender_type', 'message_type', 'content', 'transcripcion', 'created_at'])->reverse();

        $quien = ['customer' => 'Cliente', 'agent' => 'Asesor', 'system' => 'Bot'];
        $tipos = ['image' => '[foto]', 'audio' => '[nota de voz]', 'video' => '[video]', 'document' => '[documento]', 'sticker' => '[sticker]', 'location' => '[ubicación]'];

        $hilo = $mensajes->map(function ($m) use ($quien, $tipos) {
            $texto = $m->message_type === 'audio' && trim((string) $m->transcripcion) !== ''
                ? '[nota de voz] ' . trim((string) $m->transcripcion)
                : trim((string) $m->content);
            $texto = $texto !== '' ? mb_substr($texto, 0, 600) : ($tipos[$m->message_type] ?? '[' . $m->message_type . ']');

            return ($quien[$m->sender_type] ?? 'Otro') . ' (' . substr((string) $m->created_at, 11, 5) . '): ' . $texto;
        })->implode("\n");

        $datos = array_filter([
            'cliente' => $ficha['name'] ?? null,
            'identificado' => !empty($ficha['linked']) ? 'sí' : 'no (no se sabe quién es en el sistema)',
            'plan' => $ficha['plan'] ?? null,
            'estado_del_servicio' => $ficha['service_status'] ?? null,
            'deuda' => isset($ficha['debt']['total']) ? ('$ ' . number_format((float) $ficha['debt']['total'], 0, ',', '.') . ' en ' . ($ficha['debt']['count'] ?? 0) . ' factura(s)') : null,
            'ticket_abierto' => !empty($ficha['open_ticket']) ? ('#' . $ficha['open_ticket']['id'] . ' · ' . ($ficha['open_ticket']['status'] ?? '') . ' · ' . mb_substr((string) ($ficha['open_ticket']['observation'] ?? ''), 0, 200)) : 'ninguno',
            'conversaciones_anteriores' => $ficha['previous_conversations'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $contenido = "FICHA DEL CLIENTE\n" . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nCONVERSACIÓN (de la más vieja a la más nueva)\n" . ($hilo ?: '(sin mensajes)')
            . "\n\n" . $instruccion;

        try {
            $r = Ia::para($this->companyId)->mensaje($sistema, [['role' => 'user', 'content' => [['type' => 'text', 'text' => $contenido]]]], [], $maxTokens);
            \App\Services\Cobranza\UsoIa::consulta($this->companyId);

            return trim(implode("\n", array_map(fn ($b) => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', $r['content'] ?? [])), " \n\"«»");
        } catch (\Throwable $e) {
            Log::warning('[CRM] El copiloto no pudo responder', ['empresa' => $this->companyId, 'conversacion' => $conversationId, 'error' => $e->getMessage()]);

            throw new \RuntimeException('La inteligencia artificial no respondió. Vuelva a intentar en un momento.');
        }
    }
}
