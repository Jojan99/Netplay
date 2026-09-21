<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historia de la red: hasta ahora la señal se medía cada 15 minutos y se
 * olvidaba, así que no había forma de ver que un puerto se viene degradando ni
 * qué clientes están al borde. Se guardan dos resúmenes livianos (por puerto y
 * por equipo y día), no cada lectura de cada ONT.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('red_muestras')) {
            Schema::create('red_muestras', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('olt_id');
                $t->string('fsp', 20);
                $t->dateTime('medido_en');
                $t->unsignedSmallInteger('onts')->default(0);
                $t->unsignedSmallInteger('online')->default(0);
                $t->unsignedSmallInteger('offline')->default(0);
                $t->unsignedSmallInteger('al_borde')->default(0)->comment('ONT por debajo del límite de señal buena');
                $t->decimal('rx_mediana', 5, 2)->nullable();
                $t->decimal('rx_min', 5, 2)->nullable();
                $t->decimal('rx_max', 5, 2)->nullable();
                $t->timestamps();

                $t->unique(['olt_id', 'fsp', 'medido_en'], 'red_muestras_puerto_momento');
                $t->index(['olt_id', 'fsp', 'medido_en'], 'red_muestras_puerto');
            });
        }

        if (!Schema::hasTable('red_equipo_dia')) {
            Schema::create('red_equipo_dia', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('olt_id');
                $t->string('fsp', 20);
                $t->unsignedInteger('ont_id');
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('serial', 40)->nullable();
                $t->string('descripcion', 120)->nullable();
                $t->date('fecha');
                $t->unsignedSmallInteger('muestras')->default(0);
                $t->unsignedSmallInteger('muestras_offline')->default(0);
                $t->unsignedSmallInteger('caidas')->default(0)->comment('Veces que pasó de encendida a apagada');
                $t->boolean('ultima_apagada')->default(false)->comment('Cómo estaba en la medición anterior, para contar las caídas');
                $t->decimal('rx_min', 5, 2)->nullable();
                $t->decimal('rx_max', 5, 2)->nullable();
                $t->decimal('rx_suma', 8, 2)->default(0)->comment('Para el promedio, sin guardar cada lectura');
                $t->unsignedSmallInteger('rx_muestras')->default(0);
                $t->timestamps();

                $t->unique(['olt_id', 'fsp', 'ont_id', 'fecha'], 'red_equipo_dia_unico');
                $t->index(['company_id', 'fecha'], 'red_equipo_dia_empresa');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('red_muestras');
        Schema::dropIfExists('red_equipo_dia');
    }
};
