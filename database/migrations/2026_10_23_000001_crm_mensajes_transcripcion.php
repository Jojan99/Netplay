<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lo que dice una nota de voz, en texto: para el asesor, Kloe y cobranza. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_messages', 'transcripcion')) {
            Schema::table('crm_messages', function (Blueprint $t) {
                $t->text('transcripcion')->nullable();
                // null: no se intentó · «» vacía: no se pudo (no se reintenta para siempre).
                $t->unsignedTinyInteger('transcripcion_intentos')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::table('crm_messages', fn (Blueprint $t) => $t->dropColumn(['transcripcion', 'transcripcion_intentos']));
    }
};
