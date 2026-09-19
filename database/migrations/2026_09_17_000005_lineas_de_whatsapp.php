<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de líneas de WhatsApp Web por empresa.
 *
 * Hasta ahora la empresa tenía una sola línea (companies.wa_instance_id) aunque
 * el servicio Node ya permitía varias: un mensaje que entraba por la segunda
 * línea no resolvía empresa y no llegaba a ninguna bandeja, y una respuesta a
 * ese chat habría salido por la primera línea, desde otro número.
 *
 * Con el catálogo cada conversación sabe por qué línea entró y por ahí sale la
 * respuesta. companies.wa_instance_id se mantiene como "línea principal" para
 * todo lo que no cuelga de una conversación (facturas, avisos, campañas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_lineas')) {
            Schema::create('wa_lineas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                // El id de la instancia en el servicio Node (wa_instances.id).
                $t->string('instance_id', 50)->unique();
                $t->string('nombre', 100)->default('Principal');
                $t->string('telefono', 32)->nullable();
                // connected | waiting_qr | disconnected, como lo reporta el Node.
                $t->string('estado', 20)->default('disconnected');
                // Una línea borrada en el Node se desactiva, no se borra: las
                // conversaciones viejas siguen apuntando a ella.
                $t->boolean('activa')->default(true);
                $t->boolean('principal')->default(false);
                $t->timestamp('sincronizado_en')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'activa']);
            });
        }

        if (Schema::hasTable('crm_conversations') && !Schema::hasColumn('crm_conversations', 'wa_linea_id')) {
            Schema::table('crm_conversations', function (Blueprint $t) {
                $t->unsignedBigInteger('wa_linea_id')->nullable()->after('provider')->index();
            });
        }

        // También en el mensaje: si la empresa mueve una conversación de línea,
        // el historial sigue diciendo por dónde salió o entró cada mensaje.
        if (Schema::hasTable('crm_messages') && !Schema::hasColumn('crm_messages', 'wa_linea_id')) {
            Schema::table('crm_messages', function (Blueprint $t) {
                $t->unsignedBigInteger('wa_linea_id')->nullable()->after('conversation_id')->index();
            });
        }

        $this->migrarLineaActual();
    }

    /**
     * La instancia que ya tenía cada empresa entra al catálogo como principal.
     * Sin esto ninguna conversación vieja resolvería línea y el CRM quedaría
     * sin badge hasta la primera sincronización con el Node.
     */
    private function migrarLineaActual(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasTable('wa_lineas')) {
            return;
        }

        $empresas = DB::table('companies')
            ->whereNotNull('wa_instance_id')
            ->where('wa_instance_id', '<>', '')
            ->get(['id', 'name', 'wa_instance_id']);

        foreach ($empresas as $empresa) {
            if (DB::table('wa_lineas')->where('instance_id', $empresa->wa_instance_id)->exists()) {
                continue;
            }

            DB::table('wa_lineas')->insert([
                'company_id'  => $empresa->id,
                'instance_id' => $empresa->wa_instance_id,
                'nombre'      => 'Principal',
                'estado'      => 'disconnected',
                'activa'      => 1,
                'principal'   => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_messages') && Schema::hasColumn('crm_messages', 'wa_linea_id')) {
            Schema::table('crm_messages', fn (Blueprint $t) => $t->dropColumn('wa_linea_id'));
        }

        if (Schema::hasTable('crm_conversations') && Schema::hasColumn('crm_conversations', 'wa_linea_id')) {
            Schema::table('crm_conversations', fn (Blueprint $t) => $t->dropColumn('wa_linea_id'));
        }

        Schema::dropIfExists('wa_lineas');
    }
};
