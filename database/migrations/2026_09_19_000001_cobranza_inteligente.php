<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza inteligente: la plataforma detecta a quién hay que cobrarle, avisa
 * en el panel y, con autorización, un asistente conversa por WhatsApp y
 * negocia dentro de los límites que ponga la empresa.
 *
 * Sólo crea tablas nuevas: no toca las de facturación ni las del CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cobranza_configs')) {
            Schema::create('cobranza_configs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->boolean('activa')->default(false);
                // manual: se avisa y alguien autoriza; automatico: escribe solo.
                $t->string('modo', 12)->default('manual');

                // A quién se le cobra
                $t->unsignedSmallInteger('min_facturas')->default(1);
                $t->unsignedSmallInteger('min_dias_mora')->default(10);
                // Deudas más viejas suelen ser clientes que ya se fueron: 0 = sin tope.
                $t->unsignedSmallInteger('max_dias_mora')->default(120);
                $t->decimal('min_monto', 12, 2)->default(0);

                // Hasta dónde puede negociar el asistente
                $t->unsignedTinyInteger('descuento_max_pct')->default(0);
                $t->unsignedSmallInteger('descuento_dias')->default(2);
                $t->unsignedTinyInteger('cuotas_max')->default(1);
                $t->unsignedSmallInteger('plazo_max_dias')->default(15);
                $t->boolean('compromiso_suspende')->default(true);

                // Cuándo y cuánto escribe
                $t->string('hora_desde', 5)->default('08:00');
                $t->string('hora_hasta', 5)->default('18:00');
                $t->string('dias', 20)->default('1,2,3,4,5,6');
                $t->unsignedSmallInteger('max_contactos_dia')->default(20);
                $t->unsignedTinyInteger('recordatorios')->default(1);
                $t->unsignedSmallInteger('horas_entre_recordatorios')->default(24);

                $t->string('nombre_asistente', 60)->default('Asistente de cartera');
                $t->text('instrucciones')->nullable();
                $t->unsignedBigInteger('wa_linea_id')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('cobranza_casos')) {
            Schema::create('cobranza_casos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                // detectado → autorizado → contactado ⇄ negociando → acuerdo | pagado | escalado | descartado | sin_respuesta | cerrado
                $t->string('estado', 16)->default('detectado')->index();
                $t->string('resultado', 24)->nullable();
                $t->string('motivo', 255)->nullable();
                $t->decimal('deuda', 12, 2)->default(0);
                $t->unsignedSmallInteger('facturas')->default(0);
                $t->unsignedSmallInteger('dias_mora')->default(0);
                $t->string('telefono', 20)->nullable();
                $t->unsignedBigInteger('conversation_id')->nullable();
                $t->unsignedBigInteger('wa_linea_id')->nullable();
                $t->unsignedBigInteger('autorizado_por')->nullable();
                $t->timestamp('autorizado_en')->nullable();
                $t->timestamp('contactado_en')->nullable();
                $t->timestamp('ultimo_mensaje_en')->nullable();
                $t->timestamp('ultima_respuesta_en')->nullable();
                $t->unsignedTinyInteger('recordatorios')->default(0);
                $t->json('compromisos')->nullable();
                $t->json('descuentos')->nullable();
                $t->timestamp('descuento_vence')->nullable();
                $t->text('resumen')->nullable();
                // La conversación con el modelo (sólo texto y herramientas).
                $t->json('historial')->nullable();
                $t->boolean('visto')->default(false);
                $t->timestamps();

                $t->index(['company_id', 'estado']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranza_casos');
        Schema::dropIfExists('cobranza_configs');
    }
};
