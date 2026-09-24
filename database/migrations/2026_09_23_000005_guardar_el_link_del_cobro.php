<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guardar el link del cobro para poder reusarlo.
 *
 * Hasta ahora el link se generaba, se mandaba y se olvidaba. Si el cliente
 * volvía a pedir pagar lo mismo, se creaba otro cobro: dos links vivos por la
 * misma factura y la posibilidad de que pagara dos veces. Con el link guardado
 * se le puede devolver el que ya tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_payment_transactions', function (Blueprint $t) {
            if (!Schema::hasColumn('online_payment_transactions', 'payment_url')) {
                $t->text('payment_url')->nullable()->after('gateway_transaction_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('online_payment_transactions', function (Blueprint $t) {
            if (Schema::hasColumn('online_payment_transactions', 'payment_url')) {
                $t->dropColumn('payment_url');
            }
        });
    }
};
