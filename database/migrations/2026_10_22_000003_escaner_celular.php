<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El celular como lector: el computador muestra un QR, el celular lo escanea y presta su
 * cámara. Cada serial que lee el celular llega al computador y el computador le contesta si
 * sirvió (agregado, repetido…), para que el celular pite verde o rojo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('escaner_sesiones')) {
            Schema::create('escaner_sesiones', function (Blueprint $t) {
                $t->id();
                $t->string('token', 64)->unique();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->string('modo', 10)->default('producto');
                $t->string('titulo', 120)->nullable();
                $t->timestamp('conectado_en')->nullable();
                $t->timestamp('expira_en');
                $t->timestamp('cerrada_en')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('escaner_lecturas')) {
            Schema::create('escaner_lecturas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sesion_id')->index();
                $t->string('codigo', 120);
                // null: el computador todavía no contesta. 1: sirvió. 0: no sirvió.
                $t->boolean('bien')->nullable();
                $t->string('texto', 160)->nullable();
                $t->timestamp('respondida_en')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('escaner_lecturas');
        Schema::dropIfExists('escaner_sesiones');
    }
};
