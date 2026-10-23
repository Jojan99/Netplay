<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previas de los canales (21-10-2026): una línea propia del panel para mirarlos (la de monitoreo,
 * que no es de ningún cliente) y cuándo se tomó la última foto de cada canal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tv_paneles', function (Blueprint $t) {
            $t->unsignedInteger('monitor_xui_id')->nullable()->after('clave_fija');
            $t->string('monitor_usuario', 60)->nullable()->after('monitor_xui_id');
            $t->text('monitor_clave')->nullable()->after('monitor_usuario');
        });
        Schema::table('tv_canales', function (Blueprint $t) {
            $t->timestamp('previa_en')->nullable()->after('revisado_en');
        });
    }

    public function down(): void
    {
        Schema::table('tv_paneles', fn (Blueprint $t) => $t->dropColumn(['monitor_xui_id', 'monitor_usuario', 'monitor_clave']));
        Schema::table('tv_canales', fn (Blueprint $t) => $t->dropColumn('previa_en'));
    }
};
