<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta cuándo vio sus notificaciones cada usuario.
 *
 * Las notificaciones no se guardan: se arman en el momento con lo que ya hay
 * (avisos de la red, cobranza, aprovisionamientos). Lo único que hace falta
 * recordar es hasta dónde miró cada quien, para el puntito del encabezado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notificaciones_vistas')) {
            Schema::create('notificaciones_vistas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->dateTime('visto_hasta');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_vistas');
    }
};
