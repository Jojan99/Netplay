<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas rápidas del CRM: mensajes pre-guardados que el agente invoca con "/atajo"
 * desde el compositor del chat (como las respuestas rápidas de WhatsApp Business).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('shortcut', 40);          // sin la barra: "saludo", "horario"
            $table->string('title', 100)->nullable();
            $table->text('content');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'shortcut'], 'crm_quick_replies_company_shortcut_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quick_replies');
    }
};
