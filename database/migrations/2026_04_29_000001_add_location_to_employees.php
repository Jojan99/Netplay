<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('active');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->timestamp('last_location_update')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'last_location_update']);
        });
    }
};
