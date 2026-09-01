<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('phone', 30);
            $table->string('current_flow', 50)->nullable();
            $table->string('current_step', 50)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'phone']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_bot_sessions');
    }
};
