<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo cobra cada empresa. No todas tienen pasarela: pueden mandar un QR
 * (Nequi, Daviplata, Bancolombia…) y los datos de pago escritos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobranza_configs', function (Blueprint $t) {
            if (!Schema::hasColumn('cobranza_configs', 'pago_link')) {
                $t->boolean('pago_link')->default(true)->after('instrucciones')
                    ->comment('Mandar link de pago en línea (necesita pasarela activa)');
            }

            if (!Schema::hasColumn('cobranza_configs', 'pago_qr')) {
                $t->string('pago_qr', 255)->nullable()->after('pago_link')
                    ->comment('Imagen del QR de pago que manda el asistente');
            }

            if (!Schema::hasColumn('cobranza_configs', 'pago_texto')) {
                $t->text('pago_texto')->nullable()->after('pago_qr')
                    ->comment('Medios de pago escritos: cuentas, llaves, oficinas');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cobranza_configs', function (Blueprint $t) {
            $t->dropColumn(['pago_link', 'pago_qr', 'pago_texto']);
        });
    }
};
