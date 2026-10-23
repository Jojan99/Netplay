<?php

namespace App\Console\Commands;

use App\Services\Crm\Reparto;
use App\Support\CrmSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparte los chats que esperan sin asesor entre los que están en el panel.
 *
 * La asignación al llegar el mensaje sólo sirve si en ese momento hay alguien: lo que entró
 * antes de que el equipo abriera el panel (la noche, la mañana temprano) quedaba sin dueño.
 * En horario de atención, cada dos minutos, los chats de las últimas 24 h cuyo último mensaje
 * es del cliente pasan al asesor disponible con menos carga, el que más espera primero.
 */
class CrmRepartir extends Command
{
    protected $signature = 'crm:repartir {--limite=10 : Chats por pasada y por empresa}';

    protected $description = 'Asigna a los asesores disponibles los chats que esperan sin dueño';

    public function handle(): int
    {
        $empresas = DB::table('crm_settings')->where('auto_assign', 1)->pluck('company_id');

        foreach ($empresas as $empresa) {
            if (!CrmSettings::isOpenNow(CrmSettings::for((int) $empresa))) {
                continue;
            }

            $esperando = DB::table('crm_conversations as c')
                ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
                ->join('crm_messages as m', 'm.id', '=', DB::raw('(SELECT MAX(id) FROM crm_messages WHERE conversation_id = c.id)'))
                ->where('c.company_id', $empresa)->whereNull('c.assigned_user_id')->whereIn('c.status', ['new', 'in_progress'])
                ->where(fn ($q) => $q->whereNull('cu.is_group')->orWhere('cu.is_group', 0))
                ->where('m.sender_type', 'customer')->where('m.created_at', '>=', now()->subDay())
                ->orderBy('m.created_at')->limit(max(1, (int) $this->option('limite')))->pluck('c.id');

            $asignados = 0;
            foreach ($esperando as $id) {
                if (!Reparto::asignar((int) $id, (int) $empresa, 'reparto_en_espera')) {
                    break;   // Nadie disponible (o todos en su tope): no tiene sentido seguir.
                }
                $asignados++;
            }

            if ($asignados) {
                $this->info("Empresa {$empresa}: {$asignados} chats repartidos.");
            }
        }

        return self::SUCCESS;
    }
}
