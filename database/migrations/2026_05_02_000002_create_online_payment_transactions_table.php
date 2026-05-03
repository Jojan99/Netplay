<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('det_facturation_id');
            $table->string('reference')->unique();
            $table->string('gateway', 20);          // wompi|epayco|zonapago
            $table->boolean('sandbox')->default(true);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|failed
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->json('gateway_payload')->nullable();     // payload completo del webhook
            $table->timestamp('initiated_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('det_facturation_id');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payment_transactions');
    }
};
