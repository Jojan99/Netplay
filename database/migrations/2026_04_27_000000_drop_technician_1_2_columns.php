<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_orders', function (Blueprint $table) {
            $table->dropColumn('technician_1_id');
            $table->dropColumn('technician_2_id');
        });
    }

    public function down(): void
    {
        Schema::table('installation_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('technician_1_id')->nullable();
            $table->unsignedBigInteger('technician_2_id')->nullable();
        });
    }
};