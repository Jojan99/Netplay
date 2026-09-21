<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La VLAN que tenía cada equipo, guardada por serial.
 *
 * Al desautorizar, la OLT borra el service-port y la plataforma borra la fila
 * de la ONT: la VLAN del cliente se pierde. Si después se vuelve a autorizar
 * sin elegirla, el equipo queda registrado y sin camino de datos —prende, la
 * OLT lo ve online y el cliente no navega—. Esto la recuerda por serial para
 * devolvérsela sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('olt_vlan_recordada')) {
            Schema::create('olt_vlan_recordada', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('olt_id')->index();
                $t->string('serial', 32);
                $t->unsignedInteger('vlan');
                $t->string('descripcion', 120)->nullable();
                $t->timestamps();
                $t->unique(['olt_id', 'serial']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_vlan_recordada');
    }
};
