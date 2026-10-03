<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las facturas recurrentes de Alegra: a quién le genera factura sola cada mes.
 *
 * Es la respuesta a «¿a quién se le está facturando?»: Alegra crea la factura en la fecha
 * programada sin que nadie la pida, así que un cliente retirado en Netvula que siga aquí
 * sigue recibiendo factura ante la DIAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alegra_recurrentes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('alegra_id', 40);
            $t->string('cliente_alegra_id', 40)->nullable();
            $t->string('cliente_nombre', 190)->nullable();
            $t->date('inicio')->nullable();
            $t->date('fin')->nullable();
            $t->date('ultima')->nullable();
            $t->date('proxima')->nullable();
            $t->unsignedSmallInteger('cada_meses')->nullable();
            $t->decimal('total', 14, 2)->default(0);
            $t->string('concepto', 190)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->dateTime('sincronizada_en')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'alegra_id']);
            $t->index(['company_id', 'user_id']);
            $t->index(['company_id', 'cliente_alegra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alegra_recurrentes');
    }
};
