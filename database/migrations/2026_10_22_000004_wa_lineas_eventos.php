<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La historia de cada línea de WhatsApp: cuándo se cayó y cuándo volvió. wa_lineas sólo
 * guarda el estado de ahora, y sin historia no había cómo saber cuánto tiempo estuvo caída
 * una línea ni cuántas veces pasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_lineas_eventos')) {
            Schema::create('wa_lineas_eventos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('linea_id')->index();
                $t->string('antes', 30)->nullable();
                $t->string('estado', 30);
                $t->boolean('avisado')->default(false);
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_lineas_eventos');
    }
};
