<?php

namespace App\Console\Commands;

use App\Models\SoporteCaso;
use App\Models\SoporteConfig;
use App\Services\Red\TareasDeGestion;
use App\Services\Soporte\AgenteDeSoporte;
use App\Services\Soporte\Conversacion;
use App\Services\Soporte\Herramientas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lo que el asistente de soporte dejó andando:
 *
 *   · cambios de clave que esperaban a que el equipo quedara gestionable,
 *   · mensajes que se quedaron sin respuesta porque la IA estaba ocupada,
 *   · casos abandonados: se cierran y el bot de menús vuelve a atender.
 */
class SoporteRevisar extends Command
{
    protected $signature = 'soporte:revisar';
    protected $description = 'Termina cambios pendientes del asistente de soporte y cierra casos sin respuesta';

    /** Minutos que se espera a que el equipo aparezca en la gestión remota. */
    private const ESPERA_DEL_EQUIPO = 12;

    public function handle(): int
    {
        // Corre cada minuto: si la pasada anterior sigue (un equipo lento), ésta no se le suma.
        $candado = Cache::store('redis')->lock('soporte:revisar', 240);

        if (!$candado->get()) {
            return self::SUCCESS;
        }

        try {
            $this->revisar();
        } finally {
            $candado->release();
        }

        return self::SUCCESS;
    }

    private function revisar(): void
    {
        foreach (SoporteCaso::where('estado', 'esperando')->get() as $caso) {
            try {
                $this->pendiente($caso);
            } catch (\Throwable $e) {
                Log::warning('[Soporte] No se pudo revisar un pendiente', ['caso' => $caso->id, 'error' => $e->getMessage()]);
            }
        }

        foreach (SoporteCaso::where('estado', 'activo')->get() as $caso) {
            $cfg = SoporteConfig::deEmpresa((int) $caso->company_id);
            $ultimo = $caso->ultimo_mensaje_en ?? $caso->created_at;
            $sinContestar = !$caso->ultima_respuesta_en || $caso->ultima_respuesta_en->lt($ultimo);

            if ($sinContestar && $ultimo->lt(now()->subSeconds(90)) && $ultimo->gt(now()->subMinutes(10)) && $caso->ultimo_mensaje_id) {
                AgenteDeSoporte::lanzar((int) $caso->id, (int) $caso->ultimo_mensaje_id);
            } elseif ($sinContestar && $ultimo->lte(now()->subMinutes(10))) {
                Herramientas::escalar($caso, 'El asistente no logró contestarle al cliente. Revise la conversación.');
            } elseif (max($ultimo, $caso->ultima_respuesta_en ?? $ultimo)->lt(now()->subMinutes(max(5, (int) $cfg->minutos_inactividad)))) {
                AgenteDeSoporte::cerrar($caso, 'cerrado', $caso->ticket_id ? 'queda_con_ticket' : 'sin_respuesta');
            }
        }
    }

    /** Un cambio de clave que esperaba al equipo. */
    private function pendiente(SoporteCaso $caso): void
    {
        $p = (array) $caso->pendiente;

        if (($p['tipo'] ?? '') !== 'clave' || empty($p['clave'])) {
            $caso->fill(['estado' => 'activo', 'pendiente' => null])->save();

            return;
        }

        $candado = Cache::store('redis')->lock("soporte:caso:{$caso->id}", 180);

        if (!$candado->get()) {
            return;
        }

        try {
            $r = Herramientas::aplicarClave($caso, (string) $p['clave']);

            if (in_array($r['estado'], ['hecha', 'en_cola'], true)) {
                $caso->diagnostico = array_merge((array) $caso->diagnostico, ['acciones' => array_values(array_unique(array_merge((array) (((array) $caso->diagnostico)['acciones'] ?? []), [$r['estado'] === 'hecha' ? 'clave_cambiada' : 'clave_programada'])))]);
                $caso->fill(['estado' => 'activo', 'pendiente' => null, 'ultimo_mensaje_en' => now()])->save();

                (new Conversacion($caso))->avisar(
                    $r['estado'] === 'hecha'
                        ? 'Listo: la clave de su WiFi ya quedó cambiada. El nombre de la red sigue igual. Sus aparatos se desconectaron: vuelva a conectarlos con la clave nueva y me cuenta si le funcionó.'
                        : 'Su equipo ya quedó habilitado y la clave nueva se le aplica en unos minutos. Cuando sus aparatos se desconecten, vuelva a conectarlos con la clave nueva.',
                    'El cambio de clave que estaba en curso ya se aplicó.'
                );

                return;
            }

            $tarea = !empty($p['tarea']) ? TareasDeGestion::ver((string) $p['tarea']) : null;
            $fallo = $r['estado'] === 'error' || ($tarea && in_array($tarea['estado'], ['error', 'no_aplica', 'detenida'], true));
            $vencio = now()->diffInMinutes($p['desde'] ?? now()) >= self::ESPERA_DEL_EQUIPO;

            if (!$fallo && !$vencio) {
                return;
            }

            // No se pudo a distancia: lo sigue una persona, con ticket si la empresa lo permite.
            $cfg = SoporteConfig::deEmpresa((int) $caso->company_id);
            $caso->fill(['pendiente' => null])->save();
            $ticket = $cfg->crea_tickets ? Herramientas::crearTicket($caso, 'El cliente pidió cambiar la clave del WiFi y el equipo no se pudo gestionar a distancia. Requiere hacerlo un técnico o un asesor.', 'baja') : null;
            Herramientas::escalar($caso, 'Cambio de clave del WiFi que no se pudo hacer a distancia' . ($ticket ? " (ticket #{$ticket['id']})." : '.'));

            (new Conversacion($caso))->avisar(
                'No fue posible cambiar la clave a distancia en su equipo. ' . ($ticket ? "Le dejé el ticket #{$ticket['id']} para que uno de nuestros técnicos haga el cambio" : 'Le dejo su caso a un asesor para que le ayude con el cambio') . '. Mientras tanto su clave sigue siendo la de siempre.',
                'El cambio de clave NO se pudo hacer a distancia y el caso pasó a una persona.'
            );
        } finally {
            $candado->release();
        }
    }
}
