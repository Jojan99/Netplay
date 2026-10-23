<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuadre de caja: al final del día se compara lo que el sistema registró por cada medio de
 * pago con lo que dice el banco o lo contado en efectivo. Un cierre por empresa y día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_cierres', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('fecha');
            $t->unsignedBigInteger('cerrado_por')->nullable();
            $t->decimal('total_sistema', 14, 2)->default(0);
            $t->decimal('total_contado', 14, 2)->default(0);
            $t->decimal('diferencia', 14, 2)->default(0);
            // [{medio_id, medio, pagos, sistema, contado, diferencia}]
            $t->json('detalle')->nullable();
            $t->string('nota', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_cierres');
    }
};
