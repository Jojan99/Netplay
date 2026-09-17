<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segunda parte del importador:
 *
 * - det_facturations.concepto: el texto de la factura cuando no es la mensual
 *   del plan ("Saldo anterior de WispHub"). Sin esto la factura del saldo
 *   saldría con el nombre del plan como descripción.
 * - importacion_filas: el grupo de facturación y el router elegidos a mano
 *   para ese cliente, y la factura de saldo que se le creó.
 * - company_billing_schedules.nombre: para que el dueño reconozca sus grupos
 *   ("Quincena", "Fin de mes") y no sólo por número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('det_facturations', function (Blueprint $table) {
            $table->string('concepto', 160)->nullable()->after('number_facture');
        });

        Schema::table('importacion_filas', function (Blueprint $table) {
            $table->unsignedTinyInteger('grupo_elegido')->nullable()->after('previo');
            $table->unsignedBigInteger('router_elegido')->nullable()->after('grupo_elegido');
            $table->unsignedBigInteger('factura_id')->nullable()->after('user_id');
        });

        Schema::table('company_billing_schedules', function (Blueprint $table) {
            $table->string('nombre', 60)->nullable()->after('grupo');
        });
    }

    public function down(): void
    {
        Schema::table('det_facturations', function (Blueprint $table) {
            $table->dropColumn('concepto');
        });

        Schema::table('importacion_filas', function (Blueprint $table) {
            $table->dropColumn(['grupo_elegido', 'router_elegido', 'factura_id']);
        });

        Schema::table('company_billing_schedules', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });
    }
};
