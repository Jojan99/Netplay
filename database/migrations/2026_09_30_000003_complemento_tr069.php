<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El TR-069 deja de venir en el plan: es un complemento que cada empresa
 * contrata aparte.
 *
 *  - plataforma_planes.tr069_precio: lo que cuesta el complemento al mes en
 *    ese plan. Arranca igual en todos; queda por plan para poder cobrarle más
 *    a quien gestiona más equipos.
 *  - plataforma_suscripciones.tr069_activo / tr069_precio: si la empresa lo
 *    tiene contratado y, si se pactó algo distinto al de lista, a cuánto.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plataforma_planes', function (Blueprint $table) {
            $table->decimal('tr069_precio', 12, 2)->default(59000);
        });

        Schema::table('plataforma_suscripciones', function (Blueprint $table) {
            $table->boolean('tr069_activo')->default(false);
            $table->decimal('tr069_precio', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plataforma_planes', fn (Blueprint $table) => $table->dropColumn('tr069_precio'));
        Schema::table('plataforma_suscripciones', fn (Blueprint $table) => $table->dropColumn(['tr069_activo', 'tr069_precio']));
    }
};
