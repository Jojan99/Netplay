<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\Lids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Une los «clientes» del CRM que quedaron con un LID de WhatsApp en vez de su teléfono.
 *
 * Al 22-10-2026 había 217, con 563 mensajes de clientes: el asesor veía «Cliente
 * 88596786761837» sin saber quién era, y lo que le respondía la línea al cliente real caía ahí
 * en vez de en su chat. Con la equivalencia que conoce el servicio de WhatsApp:
 *   · si el cliente real ya existe, sus chats pasan a él; un chat abierto por la misma línea se
 *     junta con el que ya tenía (los mensajes se mueven, el del LID queda cerrado);
 *   · si no existe, al del LID se le pone el teléfono real.
 */
class CrmUnirLids extends Command
{
    protected $signature = 'crm:unir-lids {--empresa= : Sólo esta empresa} {--probar : Muestra lo que haría, sin cambiar nada}';

    protected $description = 'Pasa a su cliente real los chats que quedaron con un LID de WhatsApp';

    public function handle(): int
    {
        $probar = (bool) $this->option('probar');
        $resumen = ['sin_equivalencia' => 0, 'renombrados' => 0, 'unidos' => 0, 'chats_juntados' => 0, 'chats_pasados' => 0, 'mensajes_movidos' => 0];

        $lids = DB::table('crm_customers')
            ->where(fn ($q) => $q->whereNull('is_group')->orWhere('is_group', 0))
            ->whereRaw("LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) >= 14")
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->get();

        foreach ($lids as $c) {
            $real = Lids::telefono($c->phone);

            if (!$real) {
                $resumen['sin_equivalencia']++;
                continue;
            }

            $destino = DB::table('crm_customers')->where('company_id', $c->company_id)->where('id', '<>', $c->id)
                ->where(fn ($q) => $q->whereNull('is_group')->orWhere('is_group', 0))
                ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ?", [substr($real, -10)])
                ->orderBy('id')->first();

            if (!$destino) {
                $resumen['renombrados']++;
                if (!$probar) {
                    DB::table('crm_customers')->where('id', $c->id)->update([
                        'phone' => $real, 'name' => in_array(trim((string) $c->name), ['', 'Cliente'], true) ? $real : $c->name, 'updated_at' => now(),
                    ]);
                }
                continue;
            }

            $resumen['unidos']++;

            foreach (DB::table('crm_conversations')->where('customer_id', $c->id)->get() as $conv) {
                $abierta = DB::table('crm_conversations')->where('customer_id', $destino->id)->where('id', '<>', $conv->id)
                    ->where('provider', $conv->provider)->where('status', '<>', 'closed')
                    ->when($conv->wa_linea_id, fn ($q) => $q->where('wa_linea_id', $conv->wa_linea_id))
                    ->orderByDesc('last_message_at')->first();

                if ($abierta) {
                    $resumen['chats_juntados']++;
                    $n = DB::table('crm_messages')->where('conversation_id', $conv->id)->count();
                    $resumen['mensajes_movidos'] += $n;

                    if (!$probar) {
                        // UPDATE IGNORE: un mensaje que ya esté en el chat destino (mismo id de WhatsApp) se queda donde está.
                        DB::statement('UPDATE IGNORE crm_messages SET conversation_id = ? WHERE conversation_id = ?', [$abierta->id, $conv->id]);
                        $ultimo = DB::table('crm_messages')->where('conversation_id', $abierta->id)->max('created_at');
                        DB::table('crm_conversations')->where('id', $abierta->id)->update(['last_message_at' => $ultimo, 'updated_at' => now()]);
                        DB::table('crm_conversations')->where('id', $conv->id)->update(['customer_id' => $destino->id, 'status' => 'closed', 'updated_at' => now()]);
                    }
                } else {
                    $resumen['chats_pasados']++;
                    if (!$probar) {
                        DB::table('crm_conversations')->where('id', $conv->id)->update(['customer_id' => $destino->id, 'updated_at' => now()]);
                    }
                }
            }

            // Si el del LID ya sabía quién era (vinculado a mano) y el real no, se le pasa.
            if (!$probar && $c->user_id && !$destino->user_id) {
                DB::table('crm_customers')->where('id', $destino->id)->update(['user_id' => $c->user_id, 'updated_at' => now()]);
            }
        }

        foreach ($resumen as $k => $v) {
            $this->line(str_pad($k, 18) . $v);
        }
        $this->info($probar ? 'Prueba: no se cambió nada.' : 'Listo.');

        return self::SUCCESS;
    }
}
