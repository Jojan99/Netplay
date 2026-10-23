<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los no leídos, en el servidor: compartidos entre asesores y a salvo de una recarga. Antes
 * vivían en el navegador de cada uno: al recargar se perdían y otro asesor no los veía.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_conversations', 'sin_leer')) {
            Schema::table('crm_conversations', fn (Blueprint $t) => $t->unsignedSmallInteger('sin_leer')->default(0));
        }
    }

    public function down(): void
    {
        Schema::table('crm_conversations', fn (Blueprint $t) => $t->dropColumn('sin_leer'));
    }
};
