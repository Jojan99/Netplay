<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién es, del lado del cliente, cada WhatsApp que nos escribe.
 *
 * Hoy el bot le pide la cédula en cada conversación, y a quien escribe sin
 * teléfono —las cuentas nuevas de WhatsApp llegan identificadas solo por
 * usuario— además le pide el celular registrado para comprobar que es el
 * titular. Repetir eso en cada consulta es molesto y no aporta nada: la
 * comprobación ya se hizo una vez.
 *
 * Aquí queda esa comprobación. Mientras no venza, el bot sabe con quién habla
 * y entra directo a las facturas.
 *
 * `sender` guarda un teléfono o una identidad ("CO.155…"), por eso es texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('sender', 64);
            $table->unsignedBigInteger('user_id');
            $table->string('dni', 30);

            // El celular con el que se comprobó la titularidad. Se guarda para
            // poder explicar después por qué se le dio acceso a esta cuenta.
            $table->string('verified_phone', 20)->nullable();

            $table->timestamp('verified_at');

            // El reconocimiento caduca: un número reasignado no puede heredar
            // para siempre el acceso a las facturas de otra persona.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // Un WhatsApp representa a un solo cliente dentro de una empresa.
            $table->unique(['company_id', 'sender']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_identities');
    }
};
