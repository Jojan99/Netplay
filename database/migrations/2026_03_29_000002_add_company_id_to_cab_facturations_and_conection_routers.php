<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cab_facturations', 'company_id')) {
            Schema::table('cab_facturations', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->index()->after('user_id');
                $table->foreign('company_id')->references('id')->on('companies');
            });
        }

        if (!Schema::hasColumn('conection_routers', 'company_id')) {
            Schema::table('conection_routers', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->index()->after('user_id');
                $table->foreign('company_id')->references('id')->on('companies');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cab_facturations', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('conection_routers', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
