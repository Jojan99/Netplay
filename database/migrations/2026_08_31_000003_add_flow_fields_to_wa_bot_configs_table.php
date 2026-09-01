<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_configs', function (Blueprint $table) {
            $table->string('menu_type', 20)->default('text')->after('welcome_message');
            $table->string('menu_title', 200)->nullable()->after('menu_type');
            $table->json('flows')->nullable()->after('options');
            $table->json('variables')->nullable()->after('flows');
            $table->json('settings')->nullable()->after('variables');
        });
    }

    public function down(): void
    {
        Schema::table('wa_bot_configs', function (Blueprint $table) {
            $table->dropColumn(['menu_type', 'menu_title', 'flows', 'variables', 'settings']);
        });
    }
};
