<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olt_onts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_data_id')->nullable()->after('description');
            $table->index('user_data_id');
        });
    }

    public function down(): void
    {
        Schema::table('olt_onts', function (Blueprint $table) {
            $table->dropIndex(['user_data_id']);
            $table->dropColumn('user_data_id');
        });
    }
};
