<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El complemento TR-069 se cobra por tramos de equipos (config
 * plataforma.complementos.tr069.tramos), no con un precio por plan: la columna
 * que se creó para eso ya no se usa.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plataforma_planes', fn (Blueprint $table) => $table->dropColumn('tr069_precio'));
    }

    public function down(): void
    {
        Schema::table('plataforma_planes', fn (Blueprint $table) => $table->decimal('tr069_precio', 12, 2)->default(59000));
    }
};
