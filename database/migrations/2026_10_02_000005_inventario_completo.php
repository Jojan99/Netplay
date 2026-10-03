<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El inventario pasa de contar cantidades a saber dónde está cada equipo.
 *
 *  - inventory_units: una fila por equipo con serial. Dice si está en bodega, con qué técnico
 *    y desde cuándo, o en la casa de qué cliente.
 *  - inventory_custodias: el material sin serial (cable, conectores) que tiene cada técnico.
 *  - inventories.quantity sigue siendo lo que hay EN BODEGA. Lo que tiene un técnico ya salió
 *    de la bodega pero no se ha gastado: se cuenta aparte.
 *  - Los movimientos ganan dos tipos (entrega y devolución), por eso «type» deja de ser un ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $t) {
            // El código de barras de la caja (el del fabricante), para reconocer el producto al escanear.
            $t->string('barcode', 80)->nullable()->after('code');
            // Se lleva equipo por equipo, con su serial.
            $t->boolean('usa_serial')->default(false)->after('unit');
            // Cuándo se avisó que está bajo: para no repetir el aviso todos los días.
            $t->dateTime('aviso_bajo_en')->nullable();
            $t->index(['company_id', 'barcode'], 'idx_inv_company_barcode');
        });

        DB::statement("ALTER TABLE inventory_movements MODIFY `type` VARCHAR(20) NOT NULL");

        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('tecnico_id')->nullable()->after('user_id');
            $t->index(['company_id', 'serial_number'], 'idx_mov_company_serial');
            $t->index(['company_id', 'tecnico_id'], 'idx_mov_company_tecnico');
        });

        Schema::create('inventory_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('inventory_id');
            // Como lo dice la etiqueta, en mayúsculas y sin espacios.
            $t->string('serial', 60);
            // La forma única para comparar con la OLT (ver App\Support\Serial).
            $t->string('serial_canonico', 60);
            $t->string('mac', 20)->nullable();
            // bodega | tecnico | instalada | danada | baja
            $t->string('estado', 15)->default('bodega');
            $t->unsignedBigInteger('tecnico_id')->nullable();
            $t->dateTime('entregada_en')->nullable();
            $t->dateTime('instalada_en')->nullable();
            $t->unsignedBigInteger('installation_order_id')->nullable();
            $t->unsignedBigInteger('cliente_user_id')->nullable();
            $t->string('nota', 255)->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'serial_canonico'], 'uq_unit_company_serial');
            $t->index(['company_id', 'inventory_id', 'estado'], 'idx_unit_item_estado');
            $t->index(['company_id', 'tecnico_id', 'estado'], 'idx_unit_tecnico');
        });

        Schema::create('inventory_custodias', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('inventory_id');
            $t->unsignedBigInteger('tecnico_id');
            $t->decimal('cantidad', 10, 2)->default(0);
            // Desde cuándo tiene lo que hoy tiene: se reinicia cuando devuelve o gasta todo.
            $t->dateTime('desde')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'inventory_id', 'tecnico_id'], 'uq_custodia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_custodias');
        Schema::dropIfExists('inventory_units');
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropIndex('idx_mov_company_serial');
            $t->dropIndex('idx_mov_company_tecnico');
            $t->dropColumn('tecnico_id');
        });
        Schema::table('inventories', function (Blueprint $t) {
            $t->dropIndex('idx_inv_company_barcode');
            $t->dropColumn(['barcode', 'usa_serial', 'aviso_bajo_en']);
        });
    }
};
