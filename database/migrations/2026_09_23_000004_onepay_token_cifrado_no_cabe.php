<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_webhook_token tiene que ser TEXT, no VARCHAR(191).
 *
 * El token en sí son 60 caracteres y entraba de sobra, pero la columna está
 * cifrada: lo que se guarda es el sobre de Laravel —IV, valor, MAC y etiqueta
 * en base64—, que pasa de 400 caracteres. Las otras llaves cifradas ya eran
 * TEXT por esto mismo; ésta se creó a mano y se quedó corta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `companies` MODIFY `pg_webhook_token` TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `companies` MODIFY `pg_webhook_token` VARCHAR(191) NULL');
    }
};
