<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los correos que Netvula le manda a cada empresa por su suscripción (recordatorio de pago,
 * vencido, último aviso, suspendida, reactivada). Se anota cada uno para no repetirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plataforma_avisos_correo', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('tipo', 30);
            // A qué se refiere: la fecha del vencimiento o del límite. Un mismo aviso sale una sola vez.
            $t->string('referencia', 40);
            $t->string('para', 400)->nullable();
            $t->boolean('enviado')->default(false);
            $t->string('detalle', 300)->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'tipo', 'referencia'], 'uq_aviso_correo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_avisos_correo');
    }
};
