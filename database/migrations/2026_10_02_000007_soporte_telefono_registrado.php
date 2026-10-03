<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistente de soporte: a quién se le puede cambiar la clave del WiFi.
 * Por defecto sólo a quien escribe desde el teléfono registrado del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soporte_configs', function (Blueprint $t) {
            if (!Schema::hasColumn('soporte_configs', 'exige_telefono_registrado')) {
                $t->boolean('exige_telefono_registrado')->default(true)->after('permite_cambiar_clave');
            }
        });
    }

    public function down(): void
    {
        Schema::table('soporte_configs', function (Blueprint $t) {
            $t->dropColumn('exige_telefono_registrado');
        });
    }
};
