<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tablero que armó cada usuario.
 *
 * Los paneles del inicio se pueden poner, quitar y mover. Lo único que hace
 * falta guardar es cuáles quedaron y en qué orden: los datos de cada panel se
 * piden aparte, como siempre. Un usuario sin fila ve el tablero de fábrica.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tablero_usuario')) {
            Schema::create('tablero_usuario', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->unsignedBigInteger('company_id')->index();
                $t->json('paneles');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tablero_usuario');
    }
};
