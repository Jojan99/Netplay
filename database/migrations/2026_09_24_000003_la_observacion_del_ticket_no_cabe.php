<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La observación del ticket era varchar(250) y se quedó corta.
 *
 * Desde que las pantallas de red crean el ticket con la medición adentro
 * —«Señal baja: potencia promedio -27,2 dBm… Dónde: puerto 0/0/7:99, OLT NH,
 * serial 48575443CDC398AC»— el texto pasa de los 250 caracteres y MySQL, en
 * modo estricto, rechaza el insert entero: el ticket no se creaba y la
 * pantalla mostraba «Server Error».
 *
 * También le pasaba a cualquiera que escribiera una observación larga a mano;
 * simplemente nadie lo había hecho todavía (la más larga guardada tenía 206).
 *
 * Se pasa a TEXT: una observación es prosa, no un código, y no tiene por qué
 * tener tope. No se toca nada más de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `tickets` MODIFY `observation` TEXT NULL');
    }

    public function down(): void
    {
        // Volver a 250 cortaría texto ya guardado; se deja lo que hay.
        DB::statement('ALTER TABLE `tickets` MODIFY `observation` VARCHAR(250) NULL');
    }
};
