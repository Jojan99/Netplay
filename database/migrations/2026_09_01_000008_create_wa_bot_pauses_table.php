<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_bot_pauses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('provider', 20)->default('meta');
            $table->string('phone', 20);
            $table->unsignedBigInteger('paused_by')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'phone'], 'wa_bot_pauses_company_provider_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_bot_pauses');
    }
};