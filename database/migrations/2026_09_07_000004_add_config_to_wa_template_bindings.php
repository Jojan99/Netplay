<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes propios de cada automatización.
 *
 * El recordatorio de pago necesita saber cuántos días antes avisar y contra
 * qué fecha contarlos, y eso cambia por empresa. Guardarlo aquí evita una
 * tabla nueva y deja cada regla junto a la plantilla que la ejecuta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_template_bindings', function (Blueprint $table) {
            $table->json('config')->nullable()->after('params');
        });
    }

    public function down(): void
    {
        Schema::table('wa_template_bindings', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
