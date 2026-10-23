<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** La foto del chat que se registró como comprobante de pago: se ve en la burbuja y no se registra dos veces. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_messages', 'payment_proof_id')) {
            Schema::table('crm_messages', fn (Blueprint $t) => $t->unsignedBigInteger('payment_proof_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        Schema::table('crm_messages', fn (Blueprint $t) => $t->dropColumn('payment_proof_id'));
    }
};
