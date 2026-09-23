<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué línea de WhatsApp llegó el comprobante.
 *
 * Con una sola línea daba igual. Con varias, quien revisa necesita saber si
 * el cliente escribió a la principal o a la de un asesor: es la diferencia
 * entre buscar la conversación en un lado o en otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $t) {
            if (!Schema::hasColumn('payment_proofs', 'wa_linea_id')) {
                $t->unsignedBigInteger('wa_linea_id')->nullable()->after('source');
                $t->index('wa_linea_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $t) {
            if (Schema::hasColumn('payment_proofs', 'wa_linea_id')) {
                $t->dropIndex(['wa_linea_id']);
                $t->dropColumn('wa_linea_id');
            }
        });
    }
};
