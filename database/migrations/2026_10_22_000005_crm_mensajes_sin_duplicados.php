<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un mensaje de WhatsApp entra una sola vez por conversación.
 *
 * El control era «consultar y después insertar»: si el webhook llegaba dos veces a la vez,
 * pasaban los dos (47 mensajes repetidos al 22-10-2026, todos idénticos). Se guardan los
 * repetidos en una tabla aparte, se quitan, y un índice único lo impide de aquí en adelante.
 * El mismo mensaje en dos conversaciones (un grupo que ven dos líneas) sigue siendo válido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $repetidos = DB::table('crm_messages as m')
            ->whereNotNull('m.external_id')->where('m.external_id', '<>', '')
            ->whereExists(fn ($q) => $q->from('crm_messages as o')->whereColumn('o.conversation_id', 'm.conversation_id')
                ->whereColumn('o.external_id', 'm.external_id')->whereColumn('o.id', '<', 'm.id'))
            ->pluck('m.id');

        if ($repetidos->isNotEmpty()) {
            if (!Schema::hasTable('crm_messages_repetidos_20261022')) {
                DB::statement('CREATE TABLE crm_messages_repetidos_20261022 LIKE crm_messages');
            }
            foreach ($repetidos->chunk(500) as $lote) {
                DB::statement('INSERT IGNORE INTO crm_messages_repetidos_20261022 SELECT * FROM crm_messages WHERE id IN (' . $lote->implode(',') . ')');
                DB::table('crm_messages')->whereIn('id', $lote)->delete();
            }
        }

        $existe = collect(DB::select("SHOW INDEX FROM crm_messages WHERE Key_name = 'crm_messages_conversacion_externo_unico'"))->isNotEmpty();
        if (!$existe) {
            DB::statement('ALTER TABLE crm_messages ADD UNIQUE INDEX crm_messages_conversacion_externo_unico (conversation_id, external_id)');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE crm_messages DROP INDEX crm_messages_conversacion_externo_unico');
    }
};
