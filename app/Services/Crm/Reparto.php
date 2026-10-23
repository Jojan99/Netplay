<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reparte los chats entre los asesores que están: los que usaron el panel en los últimos
 * minutos y no llegaron a su tope de chats (crm_agents.max_chats; 0 es sin tope), contando
 * sólo los chats con movimiento en las últimas 24 h. Al que menos carga tiene.
 *
 * Antes la asignación automática le daba el chat a cualquier asesor «activo» aunque no hubiera
 * abierto el panel en todo el día, y el cliente esperaba igual. Si no hay nadie, el chat queda
 * sin asignar y lo cubre el aviso de clientes esperando (crm:alertar-esperas).
 */
class Reparto
{
    /** Sin usar el panel en este tiempo, el asesor no está para recibir chats. */
    public const MINUTOS_PRESENTE = 10;

    /** El asesor está usando el panel. Una escritura por minuto como mucho. */
    public static function presente(int $companyId, int $userId): void
    {
        if (!Cache::add("crm:presente:{$companyId}:{$userId}", 1, 60)) {
            return;
        }

        DB::table('crm_agents')->where('company_id', $companyId)->where('user_id', $userId)->update(['ultima_actividad' => now()]);
    }

    /** El asesor disponible con menos chats abiertos, o null. */
    public static function libre(int $companyId): ?int
    {
        $fila = DB::table('crm_agents as a')
            // La carga es lo que tiene en movimiento hoy: un chat abierto hace semanas que nadie
            // cerró no es trabajo, y con él una asesora con 89 «abiertos» no recibía nunca nada.
            ->leftJoin('crm_conversations as c', fn ($j) => $j->on('c.assigned_user_id', '=', 'a.user_id')->where('c.company_id', $companyId)
                ->whereIn('c.status', ['new', 'in_progress'])->where('c.last_message_at', '>=', now()->subDay()))
            ->where('a.company_id', $companyId)->where('a.active', 1)
            ->where('a.ultima_actividad', '>=', now()->subMinutes(self::MINUTOS_PRESENTE))
            ->groupBy('a.user_id', 'a.max_chats')
            ->havingRaw('COALESCE(a.max_chats, 0) = 0 OR COUNT(c.id) < a.max_chats')
            ->selectRaw('a.user_id, COUNT(c.id) as abiertos')
            ->orderBy('abiertos')->orderByRaw('RAND()')
            ->first();

        return $fila ? (int) $fila->user_id : null;
    }

    /** @return bool si se asignó */
    public static function asignar(int $conversationId, int $companyId, string $motivo = 'auto_assign_inbound'): bool
    {
        $conv = DB::table('crm_conversations')->where('id', $conversationId)->first(['assigned_user_id', 'status']);
        if (!$conv || $conv->assigned_user_id || $conv->status === 'closed') {
            return false;
        }

        $asesor = self::libre($companyId);
        if (!$asesor) {
            return false;
        }

        // Sólo si sigue sin dueño: dos repartos a la vez no le dan el mismo chat a dos personas.
        $tomado = DB::table('crm_conversations')->where('id', $conversationId)->whereNull('assigned_user_id')
            ->update(['assigned_user_id' => $asesor, 'updated_at' => now()]);

        if ($tomado) {
            DB::table('crm_conversation_assignments')->insert([
                'conversation_id' => $conversationId, 'from_user_id' => null, 'to_user_id' => $asesor, 'reason' => $motivo, 'created_at' => now(),
            ]);
        }

        return (bool) $tomado;
    }
}
