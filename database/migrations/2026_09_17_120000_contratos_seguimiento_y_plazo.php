<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento del contrato enviado al cliente, plazo de la plantilla y
 * constancia de aceptación de los términos.
 *
 * - client_contracts.sent_at / sent_channel: cuándo y por dónde se le mandó el
 *   link, para que el operador no tenga que acordarse.
 * - client_contracts.opened_at: la primera vez que el cliente abrió la página de
 *   firma. Es la diferencia entre "no le llegó" y "lo abrió y no firmó".
 * - client_contracts.accepted_at / accept_ip / accept_user_agent /
 *   accepted_terms: la evidencia de la aceptación. El texto se guarda completo
 *   porque lo que vale es QUÉ aceptó, no sólo que marcó una casilla.
 * - contracts.terminos: el texto de aceptación que edita cada empresa.
 * - contracts.plazo: el panel ya pedía los meses de permanencia y los mandaba,
 *   pero la columna no existía y el valor se perdía en silencio ({{plazo}} salía
 *   siempre 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('client_contracts', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('token');
            }
            if (!Schema::hasColumn('client_contracts', 'sent_channel')) {
                $table->string('sent_channel', 20)->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('client_contracts', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('sent_channel');
            }
            if (!Schema::hasColumn('client_contracts', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('opened_at');
            }
            if (!Schema::hasColumn('client_contracts', 'accept_ip')) {
                $table->string('accept_ip', 45)->nullable()->after('accepted_at');
            }
            if (!Schema::hasColumn('client_contracts', 'accept_user_agent')) {
                $table->string('accept_user_agent', 255)->nullable()->after('accept_ip');
            }
            if (!Schema::hasColumn('client_contracts', 'accepted_terms')) {
                $table->text('accepted_terms')->nullable()->after('accept_user_agent');
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'plazo')) {
                $table->unsignedSmallInteger('plazo')->nullable()->after('installation_value');
            }
            if (!Schema::hasColumn('contracts', 'terminos')) {
                $table->text('terminos')->nullable()->after('plazo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_contracts', function (Blueprint $table) {
            foreach (['sent_at', 'sent_channel', 'opened_at', 'accepted_at', 'accept_ip', 'accept_user_agent', 'accepted_terms'] as $columna) {
                if (Schema::hasColumn('client_contracts', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            foreach (['plazo', 'terminos'] as $columna) {
                if (Schema::hasColumn('contracts', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
