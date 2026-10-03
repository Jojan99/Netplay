<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta del cliente para facturarle ante la DIAN y crearlo en Alegra sin adivinar:
 * barrio, ciudad, departamento, país y el indicativo de su teléfono. El tipo de documento, el
 * estrato y el municipio (código DANE) ya estaban, pero nadie los pedía en el alta.
 *
 * Y en la copia de los contactos de Alegra, el tipo de documento y la ciudad con que están
 * allá: es de donde se puede saber quién es extranjero entre los clientes que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_data', function (Blueprint $t) {
            $t->string('barrio', 120)->nullable();
            $t->string('ciudad', 80)->nullable();
            $t->string('departamento', 80)->nullable();
            $t->string('pais', 60)->nullable();
            // Indicativo del país, sin el «+»: 57 Colombia, 58 Venezuela.
            $t->string('prefijo_telefono', 6)->nullable();
        });

        Schema::table('alegra_contactos', function (Blueprint $t) {
            $t->string('tipo_documento', 10)->nullable()->after('identificacion');
            $t->string('ciudad', 80)->nullable()->after('telefono');
            $t->string('departamento', 80)->nullable()->after('ciudad');
        });
    }

    public function down(): void
    {
        Schema::table('user_data', fn (Blueprint $t) => $t->dropColumn(['barrio', 'ciudad', 'departamento', 'pais', 'prefijo_telefono']));
        Schema::table('alegra_contactos', fn (Blueprint $t) => $t->dropColumn(['tipo_documento', 'ciudad', 'departamento']));
    }
};
