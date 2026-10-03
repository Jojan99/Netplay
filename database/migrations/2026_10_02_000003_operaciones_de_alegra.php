<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de aprobación de Alegra.
 *
 * Todo lo que Netvula vaya a ESCRIBIR en Alegra (registrar un pago, anular con nota crédito,
 * quitar o crear una factura recurrente) pasa primero por aquí como propuesta. Nada sale hacia
 * Alegra sin que alguien la apruebe, salvo los tipos que el administrador dejó en automático
 * después de haberlos probado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alegra_operaciones', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            // registrar_pago | nota_credito | quitar_recurrente | crear_recurrente
            $t->string('tipo', 30);
            // propuesta | aprobada | aplicando | hecha | fallida | descartada
            $t->string('estado', 20)->default('propuesta');
            // Lo que identifica el caso («pago:123»): impide proponer dos veces lo mismo.
            $t->string('referencia', 80);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('cliente_nombre', 190)->nullable();
            $t->string('cliente_identificacion', 30)->nullable();
            $t->unsignedBigInteger('det_facturation_id')->nullable();
            $t->string('alegra_factura_id', 40)->nullable();
            $t->string('alegra_recurrente_id', 40)->nullable();
            $t->decimal('monto', 14, 2)->default(0);
            // Con qué se arma el envío (fecha, números visibles) y qué contestó Alegra.
            $t->json('datos')->nullable();
            $t->json('resultado')->nullable();
            $t->string('externo_id', 60)->nullable();
            $t->string('error', 500)->nullable();
            $t->boolean('automatica')->default(false);
            $t->unsignedBigInteger('aprobada_por')->nullable();
            $t->dateTime('aprobada_en')->nullable();
            $t->dateTime('aplicada_en')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'referencia']);
            $t->index(['company_id', 'estado', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alegra_operaciones');
    }
};
