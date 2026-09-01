<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proof_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_proof_id')->index();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('payment_proof_id')->references('id')->on('payment_proofs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proof_audits');
    }
};
