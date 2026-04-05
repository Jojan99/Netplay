<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('inventory_categories')->onDelete('set null');
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('unit')->default('unidades');
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unsignedBigInteger('inventory_id');
            $table->foreign('inventory_id')->references('id')->on('inventories')->onDelete('cascade');
            $table->enum('type', ['entrada', 'salida', 'ajuste']);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('inventory_categories');
    }
};
