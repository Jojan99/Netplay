<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Configuración del CRM por empresa: asignación automática, horario de atención, alertas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('auto_assign')->default(false);           // reparto automático al agente con menos chats
            $table->boolean('off_hours_enabled')->default(false);     // responder fuera de horario
            $table->json('business_days')->nullable();                // [1..7] (1 = lunes)
            $table->string('open_time', 5)->default('08:00');
            $table->string('close_time', 5)->default('18:00');
            $table->text('off_hours_message')->nullable();
            $table->text('welcome_message')->nullable();              // null = mensaje por defecto
            $table->unsignedSmallInteger('wait_alert_minutes')->default(15);
            $table->string('timezone', 40)->default('America/Bogota');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_settings');
    }
};
