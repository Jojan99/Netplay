<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificación del cliente antes de que la conversación entre al CRM.
 *
 * Hoy cualquier mensaje entrante crea una conversación al instante y el agente
 * no sabe con quién habla: la única pista es el teléfono, y muchos clientes
 * escriben desde otro número. Con esto, la primera vez que alguien escribe se
 * le pide la cédula, se busca en el sistema y recién ahí la conversación se
 * muestra, ya con el cliente vinculado.
 *
 * Los mensajes que llegan mientras se pregunta no se pierden: quedan retenidos
 * y se sueltan en orden cuando la persona queda identificada (o cuando se
 * decide pasarla igual al agente).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Vínculo real con el cliente de Netplay ──────────────────────────
        // crm_customers solo guardaba el teléfono. Sin esto no hay forma de
        // decir "esta conversación es de este cliente" más que adivinando.
        Schema::table('crm_customers', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_customers', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('phone');
                $table->index('user_id');
            }
            if (!Schema::hasColumn('crm_customers', 'dni')) {
                $table->string('dni', 32)->nullable()->after('user_id');
            }
        });

        // ── Estado de la identificación, por teléfono y canal ───────────────
        if (!Schema::hasTable('crm_identificaciones')) {
            Schema::create('crm_identificaciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('provider', 20)->default('netplay');
                $table->string('phone', 32);

                // preguntado   → se le pidió la cédula y se espera respuesta
                // identificado → se encontró el cliente, la conversación ya pasó
                // sin_registro → respondió pero esa cédula no existe; pasó al agente
                $table->enum('estado', ['preguntado', 'identificado', 'sin_registro'])->default('preguntado');

                $table->unsignedTinyInteger('intentos')->default(0);
                $table->string('dni', 32)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('nombre', 150)->nullable();

                // Los mensajes que llegaron mientras se preguntaba, en orden.
                $table->json('retenidos')->nullable();

                $table->timestamps();

                $table->unique(['company_id', 'provider', 'phone'], 'crm_ident_unica');
                $table->index('estado');
            });
        }

        // ── Ajustes por empresa ─────────────────────────────────────────────
        Schema::table('crm_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_settings', 'identificacion_enabled')) {
                $table->boolean('identificacion_enabled')->default(false)->after('welcome_message');
            }
            if (!Schema::hasColumn('crm_settings', 'identificacion_solo_desconocidos')) {
                // Si está activo no se le pregunta a quien ya reconocemos por su
                // teléfono, para no ponerle un trámite a un cliente conocido.
                $table->boolean('identificacion_solo_desconocidos')->default(false)->after('identificacion_enabled');
            }
            if (!Schema::hasColumn('crm_settings', 'identificacion_intentos')) {
                $table->unsignedTinyInteger('identificacion_intentos')->default(2)->after('identificacion_solo_desconocidos');
            }
            if (!Schema::hasColumn('crm_settings', 'identificacion_mensaje')) {
                $table->text('identificacion_mensaje')->nullable()->after('identificacion_intentos');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_identificaciones');

        Schema::table('crm_settings', function (Blueprint $table) {
            foreach (['identificacion_enabled', 'identificacion_solo_desconocidos', 'identificacion_intentos', 'identificacion_mensaje'] as $c) {
                if (Schema::hasColumn('crm_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('crm_customers', function (Blueprint $table) {
            foreach (['user_id', 'dni'] as $c) {
                if (Schema::hasColumn('crm_customers', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
