<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separar los equipos sin dueño de los clientes sin servicio.
 *
 * «Salud de la red» contaba como caída toda ONT apagada, tuviera cliente o no.
 * En Waonet eso daba 69 apagadas de 173 —el 40%— cuando 68 de ellas no tienen
 * ningún cliente vinculado: son altas viejas, equipos reemplazados o clientes
 * que se fueron. Clientes sin servicio había UNO.
 *
 * Un equipo sin dueño no es una caída, es inventario. Se cuenta aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('red_muestras', function (Blueprint $t) {
            if (!Schema::hasColumn('red_muestras', 'sin_cliente')) {
                $t->unsignedSmallInteger('sin_cliente')->default(0)->after('offline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('red_muestras', function (Blueprint $t) {
            if (Schema::hasColumn('red_muestras', 'sin_cliente')) {
                $t->dropColumn('sin_cliente');
            }
        });
    }
};
