<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat desde el que se pidió el link de pago.
 *
 * La confirmación del pago se mandaba al teléfono registrado en la cuenta, que
 * no tiene por qué ser el mismo desde el que el cliente pidió pagar: con las
 * cuentas sin teléfono de WhatsApp son casi siempre distintos, y el aviso caía
 * en una conversación cerrada.
 *
 * Guarda un teléfono o una identidad de usuario ("CO.155…"), por eso es texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('created_for', 64)->nullable()->after('created_via');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropColumn('created_for');
        });
    }
};
