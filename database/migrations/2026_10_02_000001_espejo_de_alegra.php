<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia local de lo que la empresa tiene en Alegra, para verlo y cruzarlo con Netvula.
 *
 * Alegra entrega 30 filas por consulta y no deja sumar ni cruzar: saber cuánto hay por cobrar
 * eran 35 consultas. Aquí se guarda lo necesario de cada factura, pago y contacto, y se
 * refresca cada cierto tiempo. Es un espejo: nunca se escribe en Alegra desde estas tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alegra_facturas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('alegra_id', 40);
            $t->string('numero', 40)->nullable();
            $t->date('fecha')->nullable();
            $t->date('vence')->nullable();
            // open | closed | draft | void, como lo dice Alegra.
            $t->string('estado', 20)->nullable();
            $t->string('cliente_alegra_id', 40)->nullable();
            $t->string('cliente_nombre', 190)->nullable();
            $t->string('cliente_identificacion', 30)->nullable();
            $t->decimal('total', 14, 2)->default(0);
            $t->decimal('pagado', 14, 2)->default(0);
            $t->decimal('saldo', 14, 2)->default(0);
            $t->decimal('impuesto', 14, 2)->default(0);
            $t->string('estado_dian', 60)->nullable();
            $t->string('cufe', 120)->nullable();
            $t->string('concepto', 190)->nullable();
            // El cliente y la cuenta de cobro de Netvula con los que casa (users.id / det_facturations.id).
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('det_facturation_id')->nullable();
            $t->dateTime('sincronizada_en')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'alegra_id']);
            $t->index(['company_id', 'estado', 'fecha']);
            $t->index(['company_id', 'cliente_identificacion']);
            $t->index(['company_id', 'user_id']);
            $t->index('det_facturation_id');
        });

        Schema::create('alegra_pagos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('alegra_id', 40);
            $t->string('numero', 40)->nullable();
            $t->date('fecha')->nullable();
            $t->decimal('monto', 14, 2)->default(0);
            $t->string('tipo', 20)->nullable();       // in | out
            $t->string('metodo', 40)->nullable();
            $t->string('banco', 120)->nullable();
            $t->string('estado', 20)->nullable();
            $t->string('cliente_nombre', 190)->nullable();
            $t->string('cliente_identificacion', 30)->nullable();
            $t->json('facturas')->nullable();
            $t->dateTime('sincronizada_en')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'alegra_id']);
            $t->index(['company_id', 'fecha']);
        });

        Schema::create('alegra_contactos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('alegra_id', 40);
            $t->string('nombre', 190)->nullable();
            $t->string('identificacion', 30)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->string('estado', 20)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->dateTime('sincronizada_en')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'alegra_id']);
            $t->index(['company_id', 'identificacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alegra_contactos');
        Schema::dropIfExists('alegra_pagos');
        Schema::dropIfExists('alegra_facturas');
    }
};
