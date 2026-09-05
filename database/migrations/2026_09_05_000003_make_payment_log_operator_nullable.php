<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Un pago online no lo registra ningún operador: entra por webhook de la
     * pasarela. La columna era NOT NULL, así que el INSERT del movimiento
     * contable fallaba y tumbaba la aplicación completa del pago.
     *
     * El único reporte que lee esta columna (FacturationRepository, el listado
     * de pagos) ya usa LEFT JOIN, así que un NULL se muestra vacío sin romper.
     *
     * Se usa SQL directo a propósito: ->change() exigiría instalar doctrine/dbal
     * en Laravel 10 y no vale la pena sumar una dependencia por una columna.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `payment_logs` MODIFY `recorded_by_user_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Solo puede revertirse si no quedan pagos online registrados.
        DB::statement('ALTER TABLE `payment_logs` MODIFY `recorded_by_user_id` BIGINT UNSIGNED NOT NULL');
    }
};
