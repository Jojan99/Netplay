<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fallas masivas: además de un puerto caído, una OLT entera (fsp «*») que deja de responder o
 * una racha de tickets de «sin servicio» de la misma OLT. Los tickets quedan atados a su falla
 * y se cierran solos cuando se resuelve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tickets', 'falla_id')) {
            Schema::table('tickets', function (Blueprint $t) {
                $t->unsignedBigInteger('falla_id')->nullable()->index();
            });
        }

        if (!Schema::hasColumn('fallas_sector', 'origen')) {
            Schema::table('fallas_sector', function (Blueprint $t) {
                // puerto: muchas ONT del mismo PON · olt: la OLT no responde · tickets: racha de reportes.
                $t->string('origen', 10)->default('puerto');
            });
        }

        Schema::table('fallas_sector_config', function (Blueprint $t) {
            if (!Schema::hasColumn('fallas_sector_config', 'tickets_para_masiva')) {
                $t->unsignedSmallInteger('tickets_para_masiva')->default(4);
            }
            if (!Schema::hasColumn('fallas_sector_config', 'minutos_ventana_tickets')) {
                $t->unsignedSmallInteger('minutos_ventana_tickets')->default(30);
            }
            if (!Schema::hasColumn('fallas_sector_config', 'cerrar_tickets')) {
                $t->boolean('cerrar_tickets')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('fallas_sector_config', function (Blueprint $t) {
            $t->dropColumn(['tickets_para_masiva', 'minutos_ventana_tickets', 'cerrar_tickets']);
        });
        Schema::table('fallas_sector', fn (Blueprint $t) => $t->dropColumn('origen'));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('falla_id'));
    }
};
