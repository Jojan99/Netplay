<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El acceso remoto a los equipos de los clientes, configurado por empresa.
 *
 * Es una red aparte —su propia VLAN— por donde la ONT pide IP y recibe, en la
 * misma respuesta, la dirección del servidor TR-069. Con eso un equipo nuevo
 * entra solo al sistema, sin que nadie le escriba nada.
 *
 * Se guarda qué VLAN y qué red se eligieron para poder rehacerlo, cambiarlo o
 * quitarlo sin adivinar qué se tocó en el router y en la OLT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestion_remota', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();

            $t->boolean('activa')->default(false);
            $t->unsignedSmallInteger('vlan')->nullable();
            $t->string('red', 20)->nullable();              // 10.30.0.0/22
            $t->string('gateway', 20)->nullable();          // 10.30.0.1
            $t->string('pool_desde', 20)->nullable();
            $t->string('pool_hasta', 20)->nullable();

            $t->unsignedBigInteger('router_id')->nullable();
            $t->string('interfaz', 40)->nullable();         // sfp1: por dónde sale hacia la OLT
            // Por cada OLT, el puerto de subida que va a esa interfaz.
            $t->json('uplinks')->nullable();

            $t->timestamp('aplicada_en')->nullable();
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::table('olt_onts', function (Blueprint $t) {
            // Cuándo se le dio acceso de gestión a esta ONT.
            $t->timestamp('gestion_en')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestion_remota');
        Schema::table('olt_onts', fn (Blueprint $t) => $t->dropColumn('gestion_en'));
    }
};
