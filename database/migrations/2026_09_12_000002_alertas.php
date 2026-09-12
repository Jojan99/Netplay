<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de la red: lo que hay que mirar antes de que llame el cliente.
 *
 * Cada aviso tiene una clave estable ("senal:14:0/0/2:1") para que una misma
 * situación no se anote dos veces: se abre una vez, se actualiza mientras
 * dure y se cierra sola cuando deja de pasar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('clave', 120);
            $t->string('tipo', 30);
            $t->string('nivel', 10)->default('aviso');   // critico | aviso
            $t->string('titulo', 160);
            $t->text('detalle')->nullable();
            $t->json('datos')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();  // el cliente afectado, si lo hay
            $t->timestamp('abierta_en');
            $t->timestamp('vista_en')->nullable();
            $t->timestamp('cerrada_en')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'clave']);
            $t->index(['company_id', 'cerrada_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
    }
};
