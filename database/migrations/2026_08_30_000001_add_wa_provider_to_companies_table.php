<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('wa_provider', 20)->nullable()->after('whatsapp_enabled')->comment('netplay|meta');
            $table->string('wa_phone_number_id', 50)->nullable()->after('wa_provider');
            $table->text('wa_access_token')->nullable()->after('wa_phone_number_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['wa_provider', 'wa_phone_number_id', 'wa_access_token']);
        });
    }
};
