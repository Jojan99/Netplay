<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué lote nació de reforzar los «posible duplicado» de éste, si se hizo. Sin esto, el botón
 * de reforzar podía tocarse dos veces y aplicar el mismo pago otra vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conciliacion_pagos_lotes', function (Blueprint $t) {
            $t->string('duplicados_lote', 60)->nullable()->after('resumen');
        });
    }

    public function down(): void
    {
        Schema::table('conciliacion_pagos_lotes', function (Blueprint $t) {
            $t->dropColumn('duplicados_lote');
        });
    }
};
