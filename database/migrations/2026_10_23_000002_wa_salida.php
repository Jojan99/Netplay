<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de salida: lo que un asesor mandó mientras la línea estaba caída. Sale solo
 * cuando la línea vuelve (wa:procesar-salida), en el orden en que se escribió.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_salida')) {
            Schema::create('wa_salida', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('conversation_id');
                $t->unsignedBigInteger('crm_message_id')->unique();
                $t->unsignedBigInteger('wa_linea_id')->nullable();
                // pendiente → enviado | fallido
                $t->string('estado', 12)->default('pendiente')->index();
                $t->unsignedTinyInteger('intentos')->default(0);
                $t->timestamp('proximo_intento')->nullable();
                $t->string('error', 250)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_salida');
    }
};
