<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('internet_plans', 'type')) {
                $table->string('type')->default('fibra')->after('description');
            }
            if (!Schema::hasColumn('internet_plans', 'active')) {
                $table->boolean('active')->default(true)->after('type');
            }
            if (!Schema::hasColumn('internet_plans', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::table('internet_plans', function (Blueprint $table) {
            $table->dropColumn(['type', 'active', 'created_at', 'updated_at']);
        });
    }
};
