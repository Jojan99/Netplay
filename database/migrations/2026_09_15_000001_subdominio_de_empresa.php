<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada empresa entra por su subdominio (netplay.netvula.com). Se toma del slug
 * sin la razón social del final (-sas, -ltda) para que quede corto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('subdomain', 40)->nullable()->unique()->after('slug');
        });

        $reservados = config('plataforma.reservados', []);
        $tomados    = [];

        foreach (DB::table('companies')->orderBy('id')->get(['id', 'slug', 'name']) as $empresa) {
            $base = \App\Services\Plataforma\EmpresaDelDominio::sugerirDesde($empresa->slug ?: $empresa->name);
            $sub  = $base;
            $i    = 2;

            while (in_array($sub, $reservados, true) || in_array($sub, $tomados, true)) {
                $sub = $base . '-' . $i++;
            }

            $tomados[] = $sub;
            DB::table('companies')->where('id', $empresa->id)->update(['subdomain' => $sub]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropColumn('subdomain');
        });
    }
};
