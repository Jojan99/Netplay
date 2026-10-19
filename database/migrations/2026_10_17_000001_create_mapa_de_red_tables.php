<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa de la red: dónde están las OLT, las cajas NAP y los clientes.
 *
 * La plataforma sabía el estado de cada cliente (en línea, señal, suspendido)
 * pero no dónde está: sólo la dirección escrita. Un puerto PON alimenta varias
 * cajas (hay puertos con más de 100 ONT), así que la caja de cada cliente no se
 * deduce del puerto: se anota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('red_puntos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->enum('tipo', ['olt', 'nap']);
            $t->string('nombre', 120);
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            // La OLT de la que cuelga (o que representa) y el puerto PON de la caja.
            $t->unsignedBigInteger('olt_id')->nullable();
            $t->string('fsp', 20)->nullable();
            $t->unsignedSmallInteger('capacidad')->nullable();
            $t->string('nota', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('cliente_ubicaciones', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            // users.id (el mismo id del cliente que usa la ficha).
            $t->unsignedBigInteger('user_id');
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedBigInteger('red_punto_id')->nullable()->index();
            // manual: puesto en el mapa; tecnico: GPS del técnico al instalar.
            $t->string('fuente', 20)->default('manual');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_ubicaciones');
        Schema::dropIfExists('red_puntos');
    }
};
