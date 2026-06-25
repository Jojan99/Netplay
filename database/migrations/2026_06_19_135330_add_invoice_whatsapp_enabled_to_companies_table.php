<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('invoice_whatsapp_enabled')->default(true)->after('whatsapp_enabled')->comment('Envio de facturas por WhatsApp');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('invoice_whatsapp_enabled');
        });
    }
};
