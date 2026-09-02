<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->string('provider', 20)->default('netplay')->after('company_id');
            $table->index(['company_id', 'provider', 'status'], 'crm_conversations_company_provider_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropIndex('crm_conversations_company_provider_status_index');
            $table->dropColumn('provider');
        });
    }
};