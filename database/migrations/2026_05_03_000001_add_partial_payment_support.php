<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_payment_transactions', function (Blueprint $table) {
            // JSON array of det_facturation IDs involved in this payment (ordered: oldest first)
            $table->json('invoice_ids')->nullable()->after('det_facturation_id');
            // Prevents double-allocation if webhook fires twice
            $table->boolean('allocation_done')->default(false)->after('gateway_payload');
        });
    }

    public function down(): void
    {
        Schema::table('online_payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['invoice_ids', 'allocation_done']);
        });
    }
};
