<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cuándo usó el panel cada asesor por última vez: la asignación automática sólo le da chats a quien está. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_agents', 'ultima_actividad')) {
            Schema::table('crm_agents', fn (Blueprint $t) => $t->timestamp('ultima_actividad')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('crm_agents', fn (Blueprint $t) => $t->dropColumn('ultima_actividad'));
    }
};
