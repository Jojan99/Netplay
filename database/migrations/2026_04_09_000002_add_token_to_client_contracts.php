<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            $table->string('token', 64)->unique()->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
