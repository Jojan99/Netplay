<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('wa_company_id', 50)->nullable()->after('email_verified_at');
            $table->string('wa_api_key', 120)->nullable()->after('wa_company_id');
            $table->string('wa_instance_id', 50)->nullable()->after('wa_api_key');
            $table->boolean('whatsapp_enabled')->default(true)->after('wa_instance_id');
        });

        Schema::table('user_data', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['wa_company_id', 'wa_api_key', 'wa_instance_id', 'whatsapp_enabled']);
        });
        Schema::table('user_data', function (Blueprint $table) {
            $table->dropColumn('whatsapp_enabled');
        });
    }
};
