<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza: cada empresa puede conectar su propia clave de Google (su propio
 * cupo gratis). Sin clave usa la de Netvula con 10 conversaciones de prueba
 * por día. Se lleva la cuenta de consultas por empresa y por día.
 *
 * Sólo agrega columnas y una tabla nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobranza_configs', function (Blueprint $t) {
            if (!Schema::hasColumn('cobranza_configs', 'ia_clave')) {
                $t->text('ia_clave')->nullable();          // cifrada
                $t->string('ia_modelos', 255)->nullable(); // cadena propia, opcional
            }
        });

        Schema::table('cobranza_casos', function (Blueprint $t) {
            if (!Schema::hasColumn('cobranza_casos', 'consultas_ia')) {
                $t->unsignedSmallInteger('consultas_ia')->default(0);
                $t->string('clave_ia', 10)->nullable(); // propia | netvula
            }
        });

        if (!Schema::hasTable('cobranza_uso_ia')) {
            Schema::create('cobranza_uso_ia', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->date('fecha');
                $t->string('clave', 10);                    // propia | netvula
                $t->unsignedInteger('consultas')->default(0);
                $t->unsignedInteger('conversaciones')->default(0);
                $t->timestamps();

                $t->unique(['company_id', 'fecha', 'clave']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranza_uso_ia');

        Schema::table('cobranza_casos', function (Blueprint $t) {
            $t->dropColumn(['consultas_ia', 'clave_ia']);
        });

        Schema::table('cobranza_configs', function (Blueprint $t) {
            $t->dropColumn(['ia_clave', 'ia_modelos']);
        });
    }
};
