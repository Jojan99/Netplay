<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un reverso es un movimiento propio.
 *
 * Deshacer un pago no es un «ajuste» cualquiera: tiene su monto en negativo y
 * su motivo, y en el historial conviene que se distinga de un ajuste contable.
 * El enum no lo contemplaba y MySQL rechazaba la fila entera.
 *
 * Sumar un valor a un enum no toca los datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_logs MODIFY COLUMN type
            ENUM('pago_completo','abono','descuento','ajuste','reverso') NOT NULL");
    }

    public function down(): void
    {
        // Lo que ya se anotó como reverso pasa a ajuste: si no, no entra.
        DB::table('payment_logs')->where('type', 'reverso')->update(['type' => 'ajuste']);

        DB::statement("ALTER TABLE payment_logs MODIFY COLUMN type
            ENUM('pago_completo','abono','descuento','ajuste') NOT NULL");
    }
};
