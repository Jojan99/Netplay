<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de WhatsApp dentro del CRM.
 *
 * Hasta ahora los mensajes de grupo se descartaban al entrar. Se pasan a
 * guardar, pero en su propia sección: un grupo no es un cliente y mezclarlos
 * en la misma bandeja llenaría de ruido la atención.
 *
 * Solo aplica a WhatsApp Web. La API de Meta no soporta grupos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El jid de un grupo (120363430765157157@g.us) son 23 caracteres y no
        // entra en el varchar(20) pensado para teléfonos. Se amplía con SQL
        // directo porque doctrine/dbal no está instalado y ->change() fallaría.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE crm_customers MODIFY phone VARCHAR(64) NOT NULL'
        );

        Schema::table('crm_customers', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_customers', 'is_group')) {
                $table->boolean('is_group')->default(false)->after('phone');
                $table->index(['company_id', 'is_group']);
            }
        });

        Schema::table('crm_messages', function (Blueprint $table) {
            // En un grupo cada mensaje lo escribe alguien distinto. Sin esto el
            // hilo sería ilegible: se vería todo como si hablara una sola persona.
            if (!Schema::hasColumn('crm_messages', 'participant_phone')) {
                $table->string('participant_phone', 32)->nullable()->after('sender_type');
            }
            if (!Schema::hasColumn('crm_messages', 'participant_name')) {
                $table->string('participant_name', 120)->nullable()->after('participant_phone');
            }
        });

        // Qué grupos se siguen. No se traen todos a propósito: una línea puede
        // estar en grupos ajenos a la atención y la bandeja se llenaría sola.
        if (!Schema::hasTable('crm_grupos_seguidos')) {
            Schema::create('crm_grupos_seguidos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('jid', 64);
                $table->string('nombre', 160)->nullable();
                $table->unsignedInteger('participantes')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'jid'], 'crm_grupo_unico');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_grupos_seguidos');

        Schema::table('crm_messages', function (Blueprint $table) {
            foreach (['participant_phone', 'participant_name'] as $c) {
                if (Schema::hasColumn('crm_messages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            if (Schema::hasColumn('crm_customers', 'is_group')) {
                $table->dropIndex(['company_id', 'is_group']);
                $table->dropColumn('is_group');
            }
        });
    }
};
