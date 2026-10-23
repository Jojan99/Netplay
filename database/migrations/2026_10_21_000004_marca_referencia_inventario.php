<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entradas por tipo de equipo, marca y referencia (21-10-2026). Un ítem del inventario es la
 * combinación de las tres; el tipo dice si cada unidad se registra con su serial (ONT, TV box) o
 * se cuenta por cantidad (conectores, RJ45).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $t) {
            $t->string('marca', 80)->nullable()->after('name');
            $t->string('referencia', 120)->nullable()->after('marca');
        });
        Schema::table('inventory_categories', function (Blueprint $t) {
            $t->boolean('usa_serial')->default(true)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('inventories', fn (Blueprint $t) => $t->dropColumn(['marca', 'referencia']));
        Schema::table('inventory_categories', fn (Blueprint $t) => $t->dropColumn('usa_serial'));
    }
};
