<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los perfiles con los que se va a autorizar la ONT.
 *
 * Se eligen al tomar el pedido, de los que la OLT tiene sincronizados, igual
 * que en la pantalla de autorizar. Antes el técnico quedaba con los perfiles
 * por defecto de la OLT, que no siempre son los que van.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_orders', function (Blueprint $tabla) {
            if (!Schema::hasColumn('installation_orders', 'line_profile_id')) {
                $tabla->unsignedInteger('line_profile_id')->nullable()->after('vlan');
            }
            if (!Schema::hasColumn('installation_orders', 'srv_profile_id')) {
                $tabla->unsignedInteger('srv_profile_id')->nullable()->after('line_profile_id');
            }
            // Varchar y no enum: el enum nos truncó datos en silencio tres
            // veces, y cada marca de OLT trae su propia lista de modelos.
            if (!Schema::hasColumn('installation_orders', 'onu_type')) {
                $tabla->string('onu_type', 60)->nullable()->after('srv_profile_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installation_orders', function (Blueprint $tabla) {
            foreach (['line_profile_id', 'srv_profile_id', 'onu_type'] as $c) {
                if (Schema::hasColumn('installation_orders', $c)) $tabla->dropColumn($c);
            }
        });
    }
};
