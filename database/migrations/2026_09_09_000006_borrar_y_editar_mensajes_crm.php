<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado y edición de mensajes en el CRM.
 *
 * La fila no se elimina: se marca. Si se borrara, un mensaje citado más
 * arriba en el hilo quedaría apuntando a la nada y la conversación perdería
 * sentido. Se muestra "mensaje eliminado", como hace WhatsApp.
 *
 * `content_original` guarda lo que decía antes de editarse: el agente tiene
 * que poder saber qué le habían escrito realmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_messages', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('crm_messages', 'deleted_by')) {
                // 'agent' o 'customer': quién lo borró
                $table->string('deleted_by', 12)->nullable()->after('deleted_at');
            }
            if (!Schema::hasColumn('crm_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('deleted_by');
            }
            if (!Schema::hasColumn('crm_messages', 'content_original')) {
                $table->text('content_original')->nullable()->after('edited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            foreach (['deleted_at', 'deleted_by', 'edited_at', 'content_original'] as $c) {
                if (Schema::hasColumn('crm_messages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
