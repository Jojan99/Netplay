<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de "ya vi la guía de primeros pasos".
 *
 * Va en `users` y no en `companies` porque la guía es de cada persona: si un
 * administrador la cierra, el resto del equipo que entre después igual la
 * necesita. Se guarda en base y no en el navegador para que no reaparezca al
 * cambiar de equipo ni se pierda al limpiar la caché.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'onboarding_done_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_done_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'onboarding_done_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_done_at');
        });
    }
};
