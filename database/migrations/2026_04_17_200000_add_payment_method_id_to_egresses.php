<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('egresses', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('egresses', function (Blueprint $table) {
            $table->dropColumn('payment_method_id');
        });
    }
};
