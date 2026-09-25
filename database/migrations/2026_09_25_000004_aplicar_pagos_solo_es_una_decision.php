<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si la empresa quiere que los comprobantes limpios se apliquen solos.
 *
 * Estaba escrito en el código: se aplicaban siempre que pasaran la revisión.
 * Pero es una decisión de cada ISP —hay quien prefiere mirar cada pago antes
 * de tocar una factura— y no algo que deba resolver un programador.
 *
 * Arranca apagado a propósito. Encenderlo empieza a mover plata sola: eso lo
 * tiene que decidir alguien, no heredarlo de una migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('aplicar_pagos_solo')->default(false)->after('pg_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('aplicar_pagos_solo');
        });
    }
};
