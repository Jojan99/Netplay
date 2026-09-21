<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Novedades de la plataforma: lo que se va agregando, mejorando o arreglando.
 *
 * Se escriben desde la consola de Netvula y cada empresa las ve en su panel,
 * sin que haya que avisarle a cada una por aparte. Si la novedad es de un
 * módulo, sólo la ven las empresas que tienen ese módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('novedades')) {
            Schema::create('novedades', function (Blueprint $t) {
                $t->id();
                $t->string('titulo', 160);
                $t->text('detalle');
                $t->enum('tipo', ['nuevo', 'mejora', 'arreglo'])->default('nuevo');
                $t->string('modulo', 60)->nullable()->comment('Sólo las empresas con este módulo la ven; vacío = todas');
                $t->string('ruta', 160)->nullable()->comment('A dónde lleva el enlace «Verlo», dentro del panel');
                $t->dateTime('publicada_en')->nullable()->comment('Vacío = borrador, no la ve nadie');
                $t->string('escrita_por', 120)->nullable();
                $t->timestamps();

                $t->index(['publicada_en', 'modulo'], 'novedades_publicadas');
            });
        }

        // Hasta dónde vio cada usuario: más liviano que una fila por novedad.
        if (!Schema::hasTable('novedades_vistas')) {
            Schema::create('novedades_vistas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->dateTime('visto_hasta');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('novedades_vistas');
        Schema::dropIfExists('novedades');
    }
};
