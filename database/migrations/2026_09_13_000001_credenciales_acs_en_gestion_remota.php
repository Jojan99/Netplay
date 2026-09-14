<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué usuario y clave se presentan los equipos al servidor TR-069, y qué
 * perfil de servidor se creó en cada OLT para mandárselos.
 *
 * Algunos equipos (C-Data, por ejemplo) no encienden su TR-069 si no reciben
 * credenciales. Se guardan para poder repetirlas en cada OLT y, más adelante,
 * exigirlas en el servidor. La clave va cifrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gestion_remota', function (Blueprint $t) {
            $t->string('acs_usuario', 64)->nullable()->after('uplinks');
            $t->text('acs_clave')->nullable()->after('acs_usuario');
            // Por cada OLT, el número de perfil de servidor TR-069 que se creó.
            $t->json('perfiles_acs')->nullable()->after('acs_clave');
        });
    }

    public function down(): void
    {
        Schema::table('gestion_remota', function (Blueprint $t) {
            $t->dropColumn(['acs_usuario', 'acs_clave', 'perfiles_acs']);
        });
    }
};
