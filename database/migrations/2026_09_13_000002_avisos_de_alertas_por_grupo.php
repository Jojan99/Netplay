<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas de la red a un grupo de WhatsApp de los técnicos.
 *
 * La empresa elige un grupo de su línea de WhatsApp Web y ahí llegan los
 * avisos críticos al momento, el "resuelto" cuando se cierran y un resumen por
 * la mañana. En cada alerta se anota cuándo se avisó, para no repetirla cada
 * vez que corre la revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('alertas_grupo_jid', 64)->nullable();
            $t->string('alertas_grupo_nombre', 160)->nullable();
        });

        Schema::table('alertas', function (Blueprint $t) {
            $t->timestamp('avisada_en')->nullable()->after('cerrada_en');
            $t->timestamp('cierre_avisado_en')->nullable()->after('avisada_en');
        });

        // Lo que ya existe se da por avisado: al asociar el grupo no tiene que
        // llegar de golpe el historial entero (había más de 500 abiertas).
        DB::table('alertas')->update(['avisada_en' => now()]);
        DB::table('alertas')->whereNotNull('cerrada_en')->update(['cierre_avisado_en' => now()]);
    }

    public function down(): void
    {
        Schema::table('alertas', function (Blueprint $t) {
            $t->dropColumn(['avisada_en', 'cierre_avisado_en']);
        });

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['alertas_grupo_jid', 'alertas_grupo_nombre']);
        });
    }
};
