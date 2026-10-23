<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El detalle de un aprovisionamiento explica qué pasó y qué hacer; con 255 caracteres no cabía
 * la explicación de un equipo que no apareció en el TR-069 (22-10-2026): la base rechazaba el
 * cambio y en su lugar quedaba guardado el error de SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE aprovisionamientos MODIFY detalle TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE aprovisionamientos MODIFY detalle VARCHAR(255) NULL');
    }
};
