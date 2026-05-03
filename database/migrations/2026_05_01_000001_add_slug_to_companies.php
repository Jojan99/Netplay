<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->unique()->after('name');
        });

        // Poblar slug para empresas existentes
        $companies = DB::table('companies')->get(['id', 'name']);
        foreach ($companies as $company) {
            $base = Str::slug($company->name, '-');
            $slug = $base;
            $i = 2;
            while (DB::table('companies')->where('slug', $slug)->where('id', '!=', $company->id)->exists()) {
                $slug = $base . '-' . $i++;
            }
            DB::table('companies')->where('id', $company->id)->update(['slug' => $slug]);
        }

        // Ahora hacerlo NOT NULL
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug', 100)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
