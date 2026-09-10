<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda con qué perfil PPP se atiende cada plan de internet.
 *
 * En PPPoE la velocidad no se pone por cliente sino en el perfil, así que
 * hace falta un perfil por plan. Sin esta relación habría que elegir el perfil
 * a mano en cada alta y acordarse de cuál corresponde a cada plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_plans', function (Blueprint $tabla) {
            if (!Schema::hasColumn('internet_plans', 'pppoe_profile')) {
                $tabla->string('pppoe_profile', 120)->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('internet_plans', function (Blueprint $tabla) {
            if (Schema::hasColumn('internet_plans', 'pppoe_profile')) {
                $tabla->dropColumn('pppoe_profile');
            }
        });
    }
};
