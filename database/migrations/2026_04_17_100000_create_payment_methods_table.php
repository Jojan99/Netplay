<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('payment_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropColumn('payment_method_id');
        });
    }
};
