<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién aprovisionó cada instalación (21-10-2026): se guardaba en el inventario pero no en la
 * orden, y no se veía en ningún lado. Y la clave de las líneas de TV nuevas: fija (1234) o al azar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('provisioned_by')->nullable()->after('provisioned_at');
        });
        Schema::table('tv_paneles', function (Blueprint $t) {
            $t->string('clave_fija', 20)->nullable()->after('pantallas');
        });

        // Las que ya se instalaron: el técnico quedó anotado en la unidad del inventario.
        DB::statement('UPDATE installation_orders o JOIN inventory_units u ON u.installation_order_id = o.id
            SET o.provisioned_by = u.tecnico_id WHERE o.provisioned_by IS NULL AND u.tecnico_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('installation_orders', fn (Blueprint $t) => $t->dropColumn('provisioned_by'));
        Schema::table('tv_paneles', fn (Blueprint $t) => $t->dropColumn('clave_fija'));
    }
};
