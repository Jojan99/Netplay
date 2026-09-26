<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada vez que se aplica una lista de pagos desde la pantalla de conciliación
 * queda un renglón aquí: quién lo hizo, con qué método, qué resultó y —lo más
 * importante— cómo estaban las facturas ANTES.
 *
 * Aplicar pagos a cientos de facturas de una vez no se puede hacer a ciegas:
 * si algo sale mal, sin este «antes» habría que reconstruirlo a mano desde una
 * copia de la base. Con él, cada factura tocada se puede volver a su estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conciliacion_pagos_lotes')) return;

        Schema::create('conciliacion_pagos_lotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            // Lo que se le pone a cada pago como marca en payment_logs.notes: con él
            // no se puede aplicar dos veces el mismo pago aunque se toque dos veces el botón.
            $t->string('lote', 60);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('payment_method_id')->nullable();
            $t->string('titulo', 120)->nullable();
            $t->string('orden', 20)->default('antigua');
            $t->date('fecha_pago')->nullable();
            $t->unsignedInteger('pagos')->default(0);
            $t->decimal('aplicado', 14, 2)->default(0);
            $t->decimal('sin_aplicar', 14, 2)->default(0);
            $t->json('resumen')->nullable();
            // longText y no json: son cientos de facturas con todas sus columnas.
            $t->longText('antes')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'lote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliacion_pagos_lotes');
    }
};
