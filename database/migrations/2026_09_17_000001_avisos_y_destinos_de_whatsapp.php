<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos y destinos de WhatsApp.
 *
 * 1. Pasa el grupo de alertas de la red (companies.alertas_grupo_jid) a la
 *    tabla de avisos como dos eventos más: alerta_red y alerta_resumen. Así el
 *    dueño los ve y los cambia desde el panel, junto con tickets y pagos.
 *    companies.alertas_grupo_jid se sigue guardando como espejo del primero.
 * 2. Arregla las etiquetas que quedaron mal codificadas ("InstalaciÃƒÂ³nes").
 * 3. Impide dos veces el mismo destino en el mismo aviso.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('wa_notification_routes')) {
            return;
        }

        // ── 1. El grupo de alertas, ahora como dos avisos configurables ──────
        if (Schema::hasColumn('companies', 'alertas_grupo_jid')) {
            $empresas = DB::table('companies')
                ->whereNotNull('alertas_grupo_jid')
                ->get(['id', 'alertas_grupo_jid', 'alertas_grupo_nombre']);

            foreach ($empresas as $empresa) {
                foreach (['alerta_red', 'alerta_resumen'] as $evento) {
                    $yaEsta = DB::table('wa_notification_routes')
                        ->where('company_id', $empresa->id)
                        ->where('event_type', $evento)
                        ->exists();

                    if ($yaEsta) {
                        continue;
                    }

                    DB::table('wa_notification_routes')->insert([
                        'company_id'  => $empresa->id,
                        'event_type'  => $evento,
                        'destination' => $empresa->alertas_grupo_jid,
                        'label'       => $empresa->alertas_grupo_nombre,
                        'enabled'     => 1,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        }

        // ── 2. Etiquetas mal codificadas ─────────────────────────────────────
        foreach (DB::table('wa_notification_routes')->whereNotNull('label')->get(['id', 'label']) as $ruta) {
            $limpia = \App\Services\NotificationRouterService::textoLimpio($ruta->label);

            if ($limpia !== $ruta->label) {
                DB::table('wa_notification_routes')->where('id', $ruta->id)->update(['label' => $limpia]);
            }
        }

        // ── 3. Un destino por aviso ──────────────────────────────────────────
        // Primero se sacan los repetidos que hubiera, dejando el más viejo.
        DB::statement('
            DELETE r FROM wa_notification_routes r
            JOIN wa_notification_routes menor
              ON menor.company_id  = r.company_id
             AND menor.event_type  = r.event_type
             AND menor.destination = r.destination
             AND menor.id          < r.id
        ');

        if (!$this->tieneIndice('wa_notification_routes', 'wa_routes_unico')) {
            Schema::table('wa_notification_routes', function ($t) {
                $t->unique(['company_id', 'event_type', 'destination'], 'wa_routes_unico');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('wa_notification_routes')) {
            return;
        }

        if ($this->tieneIndice('wa_notification_routes', 'wa_routes_unico')) {
            Schema::table('wa_notification_routes', function ($t) {
                $t->dropUnique('wa_routes_unico');
            });
        }

        DB::table('wa_notification_routes')
            ->whereIn('event_type', ['alerta_red', 'alerta_resumen'])
            ->delete();
    }

    private function tieneIndice(string $tabla, string $indice): bool
    {
        return (bool) DB::selectOne("SHOW INDEX FROM `{$tabla}` WHERE Key_name = ?", [$indice]);
    }
};
