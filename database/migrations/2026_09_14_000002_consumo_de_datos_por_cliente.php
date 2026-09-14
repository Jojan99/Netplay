<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumo de datos por cliente.
 *
 * Las ONT sólo saben cuánto pasó desde que se encendieron. Para mostrar el
 * consumo del mes se guarda la última lectura de cada equipo y lo que creció
 * desde la anterior se suma al día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumo_contadores', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->unique();
            $t->string('acs_id', 190);
            // 'pon' (enlace de fibra) o 'wan' (conexión de internet del equipo).
            $t->string('fuente', 8);
            $t->unsignedBigInteger('bajada');
            $t->unsignedBigInteger('subida');
            // Hora en que el equipo informó ese valor, no la de la lectura.
            $t->timestamp('medido_en')->nullable();
            $t->timestamps();
        });

        Schema::create('consumo_diario', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->date('fecha');
            $t->unsignedBigInteger('bajada_bytes')->default(0);
            $t->unsignedBigInteger('subida_bytes')->default(0);
            $t->timestamps();

            $t->unique(['user_id', 'fecha']);
            $t->index(['company_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumo_diario');
        Schema::dropIfExists('consumo_contadores');
    }
};
