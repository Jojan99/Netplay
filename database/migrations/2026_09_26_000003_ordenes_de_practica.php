<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de práctica: el técnico recorre el flujo completo de instalar —elegir el equipo, corregir
 * por dónde entra, terminar— con un equipo simulado, sin tocar la OLT, sin dar de alta a ningún cliente
 * y sin descontar inventario. Sirve para enseñar el módulo antes de tener una ONT real en la mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_orders', function (Blueprint $t) {
            if (!Schema::hasColumn('installation_orders', 'modo_practica')) {
                $t->boolean('modo_practica')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installation_orders', function (Blueprint $t) {
            if (Schema::hasColumn('installation_orders', 'modo_practica')) $t->dropColumn('modo_practica');
        });
    }
};
