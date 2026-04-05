<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_commitment_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commitment_id');
            $table->unsignedBigInteger('det_facturation_id');
            $table->timestamps();

            $table->foreign('commitment_id')
                ->references('id')->on('payment_commitments')->onDelete('cascade');
            $table->foreign('det_facturation_id')
                ->references('id')->on('det_facturations')->onDelete('cascade');

            $table->unique(['commitment_id', 'det_facturation_id'], 'pci_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_commitment_invoices');
    }
};
