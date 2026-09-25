<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tratos especiales: el descuento que un cliente tiene todos los meses.
 *
 * El descuento existía por factura —se ponía a mano, una por una— así que un
 * acuerdo de «a este le cobramos la mitad» había que acordarse de aplicarlo
 * cada mes, y el mes que alguien se olvidaba el cliente pagaba de más.
 *
 * Se guarda en la ficha del cliente y la facturación lo aplica sola.
 *
 * `descuento_tipo` es VARCHAR y no ENUM a propósito: un ENUM que no contempla
 * un valor lo guarda vacío sin avisar, y en esta base ya pasó tres veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_data', function (Blueprint $t) {
            // 'porcentaje' o 'valor'. NULL es que no tiene trato especial.
            $t->string('descuento_tipo', 12)->nullable()->after('control_velocidad');
            $t->decimal('descuento_valor', 10, 2)->default(0)->after('descuento_tipo');
            // Por qué se le hace: sin esto, al año nadie se acuerda.
            $t->string('descuento_motivo', 160)->nullable()->after('descuento_valor');
            // Hasta cuándo. NULL es para siempre.
            $t->date('descuento_hasta')->nullable()->after('descuento_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('user_data', function (Blueprint $t) {
            $t->dropColumn(['descuento_tipo', 'descuento_valor', 'descuento_motivo', 'descuento_hasta']);
        });
    }
};
