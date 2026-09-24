<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «bot» como quien envía un mensaje del CRM.
 *
 * El campo es un ENUM con tres valores. Escribirle uno que no está no da
 * error: MySQL trunca y aborta la fila con un aviso. Ya nos pasó con
 * payment_logs.type al agregar «reverso», y el síntoma es el mismo: el
 * mensaje simplemente no aparece y nada explica por qué.
 *
 * Hace falta porque el bot contesta por su cuenta y el agente necesita ver
 * esas respuestas: sin ellas el hilo llega cortado y hay que pedirle al
 * cliente que repita todo lo que ya había dicho.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `crm_messages` MODIFY `sender_type` ENUM('customer','agent','system','bot') NOT NULL DEFAULT 'customer'");
    }

    public function down(): void
    {
        // Los del bot pasan a «system», que es lo más parecido que queda.
        DB::table('crm_messages')->where('sender_type', 'bot')->update(['sender_type' => 'system']);
        DB::statement("ALTER TABLE `crm_messages` MODIFY `sender_type` ENUM('customer','agent','system') NOT NULL DEFAULT 'customer'");
    }
};
