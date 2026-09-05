<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // EfiPay: id de la sucursal/oficina del comercio (parámetro `office`, obligatorio)
            $table->string('pg_office_id', 32)->nullable()->after('pg_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pg_office_id');
        });
    }
};
