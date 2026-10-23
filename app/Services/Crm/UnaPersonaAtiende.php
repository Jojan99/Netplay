<?php

namespace App\Services\Crm;

use App\Models\CobranzaCaso;
use App\Services\WhatsAppService;
use App\Services\WhatsApp\LineasDeWhatsApp as Lineas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Un chat tiene un solo dueño a la vez: cuando una persona del equipo contesta, todos los
 * automáticos se callan.
 *
 * Antes, al responder desde la bandeja sólo se retiraba el asistente de soporte: el bot de la
 * línea y el de cobranza seguían hablándole al cliente encima del asesor. Ahora, venga la
 * respuesta de la bandeja o del teléfono, se retira soporte, se pausa el bot (en la plataforma
 * y en el servicio de Node, que lleva su propia lista) y el caso de cobranza pasa a la persona.
 */
class UnaPersonaAtiende
{
    public static function tomar(int $companyId, int $conversationId, ?string $provider = null, ?int $lineaId = null, ?string $instanceId = null): void
    {
        $conv = DB::table('crm_conversations as c')->leftJoin('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.id', $conversationId)->first(['c.provider', 'c.wa_linea_id', 'cu.phone', 'cu.is_group']);

        if (!$conv || $conv->is_group) {
            return;
        }

        $provider = $provider ?: ($conv->provider ?: 'netplay');
        $lineaId ??= $conv->wa_linea_id ? (int) $conv->wa_linea_id : null;
        $telefono = preg_replace('/\D/', '', (string) $conv->phone);

        \App\Services\Soporte\AgenteDeSoporte::retirar($conversationId);

        if ($telefono !== '') {
            self::pausarBot($companyId, $telefono, $provider, $lineaId, $instanceId);
            self::soltarCobranza($companyId, $telefono);
        }
    }

    public static function pausarBot(int $companyId, string $phone, string $provider, ?int $lineaId = null, ?string $instanceId = null): void
    {
        try {
            DB::table('wa_bot_pauses')->updateOrInsert(
                ['company_id' => $companyId, 'provider' => $provider, 'phone' => $phone],
                ['paused_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
        } catch (\Throwable $e) {
            Log::warning('[CRM] No se pudo pausar el bot', ['phone' => $phone, 'error' => $e->getMessage()]);
        }

        // El bot de la línea propia corre en el servicio de Node y lee SU lista: sin este aviso
        // la pausa existía en la plataforma y el bot seguía contestando igual.
        if ($provider !== 'netplay') {
            return;
        }

        try {
            (new WhatsAppService($companyId, false, 'netplay', $instanceId ?: Lineas::instanciaDeConversacion($lineaId, $companyId)))->setBotPaused($phone, true);
        } catch (\Throwable $e) {
            Log::warning('[CRM] No se pudo avisarle al servicio de WhatsApp', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /** El asistente de cobranza deja de contestar: el caso queda escalado, ya visto. */
    private static function soltarCobranza(int $companyId, string $telefono): void
    {
        try {
            $caso = CobranzaCaso::where('company_id', $companyId)->whereIn('estado', CobranzaCaso::CONVERSANDO)
                ->whereRaw("RIGHT(REGEXP_REPLACE(telefono, '[^0-9]', ''), 10) = ?", [substr($telefono, -10)])
                ->latest('id')->first();

            $caso?->fill(['estado' => 'escalado', 'motivo' => 'Lo tomó una persona desde el chat', 'visto' => true])->save();
        } catch (\Throwable $e) {
            Log::warning('[CRM] No se pudo soltar el caso de cobranza', ['telefono' => $telefono, 'error' => $e->getMessage()]);
        }
    }
}
