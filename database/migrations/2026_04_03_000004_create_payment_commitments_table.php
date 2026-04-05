<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_commitments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cab_id');          // cab_facturations.id
            $table->unsignedBigInteger('user_id');          // users.id (owner of cab)
            $table->date('commitment_date');                // deadline
            $table->decimal('amount_committed', 12, 2)->nullable(); // monto prometido
            $table->text('notes')->nullable();
            $table->boolean('auto_suspend')->default(true); // suspender si no paga
            $table->enum('status', ['pending', 'fulfilled', 'broken', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('cab_id')->references('id')->on('cab_facturations')->onDelete('cascade');
            $table->index(['status', 'commitment_date']); // for scheduler query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_commitments');
    }
};
