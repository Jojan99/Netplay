<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cierra los chats que ya no tienen nada pendiente.
 *
 * Nada cerraba las conversaciones del CRM: al 23-10-2026 había 1.239 abiertas, más de 900 sin
 * movimiento hace una semana. La bandeja, los no leídos y las cifras se llenaban de chats
 * terminados y lo que de verdad esperaba se perdía entre ellos. Reglas:
 *   · el último mensaje es del equipo (bot, asistente o asesor) y pasaron 48 h → el tema terminó;
 *   · el último es del cliente: es alguien esperando, no se cierra a las 48 h; sólo pasados 7 días,
 *     con una nota interna que lo dice;
 *   · nunca un grupo, ni un chat que esté atendiendo el asistente de soporte o el de cobranza.
 * Al cliente no se le escribe nada. Si vuelve a escribir, se le abre un chat nuevo.
 */
class CrmCerrarInactivas extends Command
{
    protected $signature = 'crm:cerrar-inactivas {--horas=48} {--dias-cliente=7} {--probar : Cuenta lo que cerraría, sin cerrar}';

    protected $description = 'Cierra los chats de WhatsApp sin movimiento';

    public function handle(): int
    {
        $horas = max(6, (int) $this->option('horas'));
        $dias = max(2, (int) $this->option('dias-cliente'));
        $probar = (bool) $this->option('probar');

        $chats = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->join('crm_messages as m', 'm.id', '=', DB::raw('(SELECT MAX(id) FROM crm_messages WHERE conversation_id = c.id)'))
            ->whereIn('c.status', ['new', 'in_progress'])
            ->where(fn ($q) => $q->whereNull('cu.is_group')->orWhere('cu.is_group', 0))
            ->where('m.created_at', '<', now()->subHours($horas))
            // Lo que atiende un asistente lo cierra el asistente.
            ->whereNotExists(fn ($q) => $q->from('soporte_casos as s')->whereColumn('s.conversation_id', 'c.id')->whereIn('s.estado', \App\Models\SoporteCaso::ABIERTOS))
            ->whereNotExists(fn ($q) => $q->from('cobranza_casos as k')->whereColumn('k.conversation_id', 'c.id')->whereIn('k.estado', \App\Models\CobranzaCaso::CONVERSANDO))
            ->get(['c.id', 'c.company_id', 'm.sender_type', 'm.created_at']);

        $terminados = $chats->filter(fn ($c) => $c->sender_type !== 'customer');
        $olvidados = $chats->filter(fn ($c) => $c->sender_type === 'customer' && $c->created_at < now()->subDays($dias));

        if (!$probar) {
            foreach ($terminados->pluck('id')->chunk(500) as $lote) {
                DB::table('crm_conversations')->whereIn('id', $lote)->update(['status' => 'closed', 'sin_leer' => 0, 'updated_at' => now()]);
            }

            foreach ($olvidados as $c) {
                // La nota va pegada al último mensaje, no con la hora de ahora: si no, el chat
                // cerrado se subía al principio de la bandeja y tapaba los que sí están activos.
                $hora = \Illuminate\Support\Carbon::parse($c->created_at)->addSecond();
                DB::table('crm_messages')->insert([
                    'conversation_id' => $c->id, 'sender_type' => 'system', 'message_type' => 'text', 'is_note' => 1,
                    'content' => "Cerrado automáticamente: el cliente escribió y en {$dias} días nadie le respondió. Si vuelve a escribir, se le abre un chat nuevo.",
                    'created_at' => $hora, 'updated_at' => $hora,
                ]);
                DB::table('crm_conversations')->where('id', $c->id)->update(['status' => 'closed', 'sin_leer' => 0, 'updated_at' => now()]);
            }
        }

        $this->info(($probar ? 'Prueba: cerraría ' : 'Cerrados ') . $terminados->count() . " chats terminados (sin movimiento en {$horas} h) y "
            . $olvidados->count() . " chats del cliente sin respuesta en {$dias} días.");

        return self::SUCCESS;
    }
}
