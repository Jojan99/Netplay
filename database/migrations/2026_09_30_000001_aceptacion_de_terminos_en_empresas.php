<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La prueba de que la empresa aceptó los términos y la política de datos.
 *
 * La Ley 1581 de 2012 (art. 9) pide autorización previa y expresa, y el
 * Decreto 1377 de 2013 (art. 8) obliga a conservar la prueba. Se guarda cuándo
 * aceptó, qué versión de los textos y desde qué IP. Las empresas anteriores a
 * esta migración quedan en null: aceptan al volver a entrar o por otro medio.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('terminos_aceptados_en')->nullable();
            $table->string('terminos_version', 20)->nullable();
            $table->string('terminos_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['terminos_aceptados_en', 'terminos_version', 'terminos_ip']);
        });
    }
};
