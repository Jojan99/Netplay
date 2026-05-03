<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_data', function (Blueprint $table) {
            $table->unsignedBigInteger('router_id')->nullable()->after('internet_plans_id')->index();
            $table->foreign('router_id')
                ->references('id')
                ->on('conection_routers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('user_data', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
            $table->dropColumn('router_id');
        });
    }
};
