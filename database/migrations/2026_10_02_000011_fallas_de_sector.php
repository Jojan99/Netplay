<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fallas de sector: cuando se caen muchas ONT del mismo puerto PON, se avisa a los
 * clientes afectados por WhatsApp y otra vez cuando vuelve el servicio. Todo lo
 * ajustable vive en fallas_sector_config, una fila por empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fallas_sector_config', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('activo')->default(false);
            // automatico: avisa solo; aprobar: espera que alguien lo apruebe desde el módulo.
            $t->string('modo', 12)->default('aprobar');
            $t->unsignedSmallInteger('minimo_onts')->default(5);
            $t->unsignedTinyInteger('porcentaje')->default(30);
            $t->unsignedSmallInteger('minutos_revision')->default(3);
            // Cuánto tiene que durar la caída antes de avisar (un parpadeo no es una falla).
            $t->unsignedSmallInteger('minutos_confirmacion')->default(5);
            // Cuánto tiene que estar de vuelta antes de darla por resuelta.
            $t->unsignedSmallInteger('minutos_resolucion')->default(5);
            $t->boolean('contar_cortes_de_luz')->default(true);
            $t->boolean('avisar_inicio')->default(true);
            $t->boolean('avisar_fin')->default(true);
            $t->boolean('avisar_grupo')->default(true);
            $t->boolean('incluir_suspendidos')->default(false);
            $t->unsignedBigInteger('wa_linea_id')->nullable();
            $t->unsignedSmallInteger('segundos_entre_mensajes')->default(3);
            $t->string('silencio_desde', 5)->nullable();
            $t->string('silencio_hasta', 5)->nullable();
            $t->text('mensaje_inicio')->nullable();
            $t->text('mensaje_fin')->nullable();
            $t->timestamp('revisado_en')->nullable();
            $t->timestamps();
        });

        Schema::create('fallas_sector_nombres', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('olt_id');
            $t->string('fsp', 20);
            $t->string('nombre', 120);
            $t->timestamps();
            $t->unique(['olt_id', 'fsp']);
        });

        Schema::create('fallas_sector', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('olt_id');
            $t->string('fsp', 20);
            // detectada → (por_aprobar) → activa → resuelta | descartada
            $t->string('estado', 14)->default('detectada');
            $t->unsignedSmallInteger('caidas')->default(0);
            $t->unsignedSmallInteger('maximo_caidas')->default(0);
            $t->unsignedSmallInteger('total')->default(0);
            $t->boolean('mantenimiento')->default(false);
            $t->timestamp('empezo_en');
            $t->timestamp('confirmada_en')->nullable();
            $t->timestamp('aprobada_en')->nullable();
            $t->unsignedBigInteger('aprobada_por')->nullable();
            $t->timestamp('volvio_en')->nullable();
            $t->timestamp('resuelta_en')->nullable();
            $t->string('nota', 250)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'estado']);
        });

        Schema::create('fallas_sector_clientes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('falla_id');
            $t->unsignedBigInteger('user_id');
            $t->string('telefono', 25)->nullable();
            $t->string('ont', 30)->nullable();
            $t->timestamp('avisado_inicio_en')->nullable();
            $t->timestamp('avisado_fin_en')->nullable();
            $t->string('error', 250)->nullable();
            $t->timestamps();
            $t->unique(['falla_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallas_sector_clientes');
        Schema::dropIfExists('fallas_sector');
        Schema::dropIfExists('fallas_sector_nombres');
        Schema::dropIfExists('fallas_sector_config');
    }
};
