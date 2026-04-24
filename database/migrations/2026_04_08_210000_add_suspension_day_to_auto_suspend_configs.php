<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_suspend_configs', function (Blueprint $table) {
            $table->unsignedInteger('suspension_day')->default(5)->after('days_overdue');
        });
    }

    public function down(): void
    {
        Schema::table('auto_suspend_configs', function (Blueprint $table) {
            $table->dropColumn('suspension_day');
        });
    }
};
