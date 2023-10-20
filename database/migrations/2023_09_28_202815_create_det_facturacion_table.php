<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('det_facturations', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('cab_id')
                ->index()
                ->nullable();
            $table->string('date_facturation');
            $table->string('number_facture');
            $table->string('date_create_facturation');
            $table->boolean('total');
            $table->float('price_total');
            $table->boolean('abone');
            $table->float('price_abone');
            $table->boolean('discount');
            $table->float('price_discount');
            $table->timestamps();
            $table->foreign('cab_id')
                ->references('id')
                ->on('cab_facturations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('det_facturacions');
    }
};
