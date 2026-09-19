<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Egresos profesionales: fecha real del gasto, proveedor, comprobante, notas,
 * adjunto privado y recurrencia; y categorías administrables por empresa.
 *
 * No se reescribe historia: los egresos viejos se siguen leyendo por
 * created_at (expense_date queda NULL) y el texto de `category` sigue mandando
 * para agrupar. Las categorías se siembran a partir de lo que cada empresa ya
 * usa, más una lista base de ISP; el catálogo es por company_id.
 */
return new class extends Migration
{
    /** Lista base para la empresa que todavía no tiene nada propio. */
    private array $base = [
        'General', 'Ancho de banda', 'Nómina', 'Arriendo', 'Energía',
        'Mantenimiento de red', 'Equipos e insumos', 'Transporte',
        'Impuestos', 'Publicidad', 'Otros',
    ];

    private array $columnasEgresos = [
        'expense_date', 'supplier', 'document_number', 'notes',
        'attachment_path', 'attachment_name', 'recurrence',
    ];

    public function up(): void
    {
        Schema::table('egresses', function (Blueprint $table) {
            if (!Schema::hasColumn('egresses', 'expense_date')) {
                $table->date('expense_date')->nullable()->comment('Fecha real del gasto, distinta del created_at');
            }
            if (!Schema::hasColumn('egresses', 'supplier')) {
                $table->string('supplier', 160)->nullable();
            }
            if (!Schema::hasColumn('egresses', 'document_number')) {
                $table->string('document_number', 60)->nullable();
            }
            if (!Schema::hasColumn('egresses', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (!Schema::hasColumn('egresses', 'attachment_path')) {
                $table->string('attachment_path', 255)->nullable()->comment('Relativa a storage/app, carpeta privada');
            }
            if (!Schema::hasColumn('egresses', 'attachment_name')) {
                $table->string('attachment_name', 160)->nullable();
            }
            if (!Schema::hasColumn('egresses', 'recurrence')) {
                $table->string('recurrence', 20)->nullable()->comment('mensual | quincenal');
            }
        });

        Schema::table('egresses', function (Blueprint $table) {
            if (!$this->hayIndice('egresses', 'egresses_company_fecha_idx')) {
                $table->index(['company_id', 'expense_date'], 'egresses_company_fecha_idx');
            }
            if (!$this->hayIndice('egresses', 'egresses_company_categoria_idx')) {
                $table->index(['company_id', 'category'], 'egresses_company_categoria_idx');
            }
        });

        Schema::table('category_egresses', function (Blueprint $table) {
            if (!Schema::hasColumn('category_egresses', 'active')) {
                $table->boolean('active')->default(true);
            }
            if (!Schema::hasColumn('category_egresses', 'color')) {
                $table->string('color', 20)->nullable();
            }
            if (!Schema::hasColumn('category_egresses', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('category_egresses', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        // El nombre de la categoría es de 50: los textos de `category` pueden ser más largos.
        DB::statement('ALTER TABLE category_egresses MODIFY name VARCHAR(120) NULL');

        Schema::table('category_egresses', function (Blueprint $table) {
            if (!$this->hayIndice('category_egresses', 'category_egresses_company_name_uq')) {
                $table->unique(['company_id', 'name'], 'category_egresses_company_name_uq');
            }
        });

        $this->sembrarCategorias();
    }

    /** Cada empresa arranca con lo que ya usa en sus egresos más la lista base. */
    private function sembrarCategorias(): void
    {
        $empresas = DB::table('companies')->pluck('id');
        $ahora    = now();

        foreach ($empresas as $empresa) {
            $suyas = DB::table('category_egresses')->where('company_id', $empresa)->pluck('name')
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))->all();

            $usadas = DB::table('egresses')->where('company_id', $empresa)
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->pluck('category')->all();

            $nuevas = [];
            foreach (array_merge($usadas, $this->base) as $nombre) {
                $nombre = trim((string) $nombre);
                $clave  = mb_strtolower($nombre);
                if ($nombre === '' || in_array($clave, $suyas, true)) {
                    continue;
                }
                $suyas[]  = $clave;
                $nuevas[] = [
                    'company_id' => $empresa,
                    'name'       => mb_substr($nombre, 0, 120),
                    'active'     => 1,
                    'color'      => null,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            if ($nuevas) {
                DB::table('category_egresses')->insert($nuevas);
            }
        }
    }

    private function hayIndice(string $tabla, string $indice): bool
    {
        return DB::select("SHOW INDEX FROM `{$tabla}` WHERE Key_name = ?", [$indice]) !== [];
    }

    public function down(): void
    {
        Schema::table('egresses', function (Blueprint $table) {
            if ($this->hayIndice('egresses', 'egresses_company_fecha_idx')) {
                $table->dropIndex('egresses_company_fecha_idx');
            }
            if ($this->hayIndice('egresses', 'egresses_company_categoria_idx')) {
                $table->dropIndex('egresses_company_categoria_idx');
            }
            foreach ($this->columnasEgresos as $c) {
                if (Schema::hasColumn('egresses', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('category_egresses', function (Blueprint $table) {
            if ($this->hayIndice('category_egresses', 'category_egresses_company_name_uq')) {
                $table->dropUnique('category_egresses_company_name_uq');
            }
            foreach (['active', 'color', 'created_at', 'updated_at'] as $c) {
                if (Schema::hasColumn('category_egresses', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
