<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los clientes con facturación electrónica pagan distinto (QR, cuenta de la empresa, llave):
 * su texto de instrucciones va aparte del de los demás clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobranza_configs', function (Blueprint $t) {
            if (!Schema::hasColumn('cobranza_configs', 'pago_texto_fe')) {
                $t->text('pago_texto_fe')->nullable()->after('pago_texto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cobranza_configs', function (Blueprint $t) {
            $t->dropColumn('pago_texto_fe');
        });
    }
};
