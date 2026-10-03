<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensiones y reactivaciones con motivo y responsable.
 *
 * El registro sólo decía «suspendido» o «reactivado»: no se sabía por qué ni quién. Y la
 * reactivación automática dependía de que existiera esa fila, así que quien se suspendía por
 * un camino que no la escribía no volvía nunca aunque pagara. Ahora la reactivación mira el
 * estado real del cliente, y lo que no debe volver solo se marca en su ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_suspend_logs', function (Blueprint $t) {
            // mora | compromiso | manual | al_dia | importado
            $t->string('motivo', 30)->nullable()->after('action');
            $t->string('detalle', 255)->nullable()->after('motivo');
            // Quién lo hizo; null = el sistema.
            $t->unsignedBigInteger('hecho_por')->nullable()->after('detalle');
        });

        Schema::table('user_data', function (Blueprint $t) {
            // Suspendido a mano por algo que no es la mora: sólo lo reactiva un operador.
            $t->boolean('no_reactivar_auto')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('auto_suspend_logs', fn (Blueprint $t) => $t->dropColumn(['motivo', 'detalle', 'hecho_por']));
        Schema::table('user_data', fn (Blueprint $t) => $t->dropColumn('no_reactivar_auto'));
    }
};
