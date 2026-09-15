<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número de serie de cada conexión a un MikroTik: con él se ve cuándo dos
 * conexiones llegan al mismo equipo por IP distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conection_routers', function (Blueprint $table) {
            $table->string('serie', 40)->nullable()->after('port');
            $table->timestamp('serie_leida_en')->nullable()->after('serie');
        });
    }

    public function down(): void
    {
        Schema::table('conection_routers', function (Blueprint $table) {
            $table->dropColumn(['serie', 'serie_leida_en']);
        });
    }
};
