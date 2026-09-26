<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombres y apellidos por separado en la orden.
 *
 * La orden guardaba el nombre completo en un campo solo, y la ficha del cliente
 * los tiene separados: al dar de alta había que partirlo adivinando dónde
 * terminaba el nombre. Con dos apellidos y dos nombres, adivinaba mal.
 *
 * client_name se conserva con el nombre completo: es lo que muestran los
 * listados, lo que va en el aviso de WhatsApp y lo que queda como descripción
 * de la ONT en la OLT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_orders', function (Blueprint $tabla) {
            if (!Schema::hasColumn('installation_orders', 'client_firstname')) {
                $tabla->string('client_firstname', 255)->nullable()->after('client_name');
            }
            if (!Schema::hasColumn('installation_orders', 'client_lastname')) {
                $tabla->string('client_lastname', 255)->nullable()->after('client_firstname');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installation_orders', function (Blueprint $tabla) {
            foreach (['client_firstname', 'client_lastname'] as $c) {
                if (Schema::hasColumn('installation_orders', $c)) $tabla->dropColumn($c);
            }
        });
    }
};
