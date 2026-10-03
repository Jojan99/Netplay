<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El asistente de soporte por WhatsApp: revisa el servicio del cliente que escribe por una
 * falla (ONT, router, señal, ping) y, si el cliente lo pide, le cambia la contraseña del WiFi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soporte_configs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('activa')->default(false);
            // Por cuál canal contesta: WhatsApp Web (líneas) y la API oficial de Meta.
            $t->boolean('canal_web')->default(true);
            $t->boolean('canal_meta')->default(true);
            $t->string('nombre_asistente', 60)->default('Asistente de soporte');
            $t->text('instrucciones')->nullable();
            $t->boolean('permite_cambiar_clave')->default(true);
            $t->boolean('permite_reiniciar')->default(true);
            $t->boolean('crea_tickets')->default(true);
            $t->unsignedSmallInteger('max_casos_dia')->default(80);
            $t->unsignedSmallInteger('minutos_inactividad')->default(30);
            // Palabras propias de la empresa que también abren un caso, separadas por coma.
            $t->string('palabras', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('soporte_casos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('telefono', 30);
            $t->string('provider', 10);                 // netplay | meta
            $t->unsignedBigInteger('wa_linea_id')->nullable();
            $t->unsignedBigInteger('conversation_id')->nullable();
            // activo | esperando | resuelto | escalado | humano | cerrado
            $t->string('estado', 12)->default('activo');
            // El número desde el que escribe es el registrado del cliente: sólo así se le cambia algo.
            $t->boolean('verificado')->default(false);
            $t->string('resultado', 40)->nullable();
            $t->text('resumen')->nullable();
            $t->json('historial')->nullable();
            $t->json('diagnostico')->nullable();
            // Algo que quedó en marcha y hay que terminar (un cambio de clave esperando a que el equipo aparezca).
            $t->text('pendiente')->nullable();
            $t->unsignedBigInteger('ticket_id')->nullable();
            $t->unsignedBigInteger('ultimo_mensaje_id')->nullable();
            $t->boolean('pauso_bot')->default(false);
            $t->unsignedSmallInteger('consultas_ia')->default(0);
            $t->dateTime('ultimo_mensaje_en')->nullable();
            $t->dateTime('ultima_respuesta_en')->nullable();
            $t->dateTime('cerrado_en')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'provider', 'telefono', 'estado'], 'idx_soporte_tel');
            $t->index(['company_id', 'estado', 'updated_at'], 'idx_soporte_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soporte_casos');
        Schema::dropIfExists('soporte_configs');
    }
};
