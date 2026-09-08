<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chat interno entre agentes de una misma empresa (directo o canal general). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_team_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id')->nullable();   // null = canal general de la empresa
            $table->text('content');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'to_user_id', 'from_user_id'], 'crm_team_messages_thread_index');
            $table->index(['company_id', 'created_at'], 'crm_team_messages_company_created_index');
        });
    }
    public function down(): void { Schema::dropIfExists('crm_team_messages'); }
};
