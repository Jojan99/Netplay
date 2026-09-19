<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los planes que vende Netvula.
 *
 * Nacieron en config/plataforma.php y ahora viven en la base para poder
 * editarlos desde la consola. Mientras la migración no esté corrida se sigue
 * leyendo el config: la página pública no se cae por eso.
 */
class PlanesDeLaPlataforma
{
    /** ¿Los planes ya están en la base? */
    public static function enBase(): bool
    {
        static $hay = null;

        return $hay ??= Schema::hasTable('plataforma_planes');
    }

    /**
     * Los planes para la página pública (sólo los activos, en su orden).
     *
     * @return list<array<string,mixed>>
     */
    public static function publicos(): array
    {
        if (!self::enBase()) {
            return (array) config('plataforma.planes', []);
        }

        $filas = DB::table('plataforma_planes')->where('activo', 1)->orderBy('orden')->orderBy('id')->get();

        // Tabla creada pero vacía: mejor mostrar el config que una página sin precios.
        if ($filas->isEmpty()) {
            return (array) config('plataforma.planes', []);
        }

        return $filas->map(fn ($p) => self::publico($p))->all();
    }

    /**
     * Todos los planes, activos o no, como los ve la consola.
     *
     * @return list<array<string,mixed>>
     */
    public static function todos(): array
    {
        if (!self::enBase()) {
            $orden = 0;

            return array_map(function ($p) use (&$orden) {
                return [
                    'id'             => null,
                    'clave'          => $p['clave'],
                    'nombre'         => $p['nombre'],
                    'para'           => $p['para'] ?? null,
                    'precio_mensual' => $p['precio_mensual'] ?? null,
                    'precio_anual'   => $p['precio_anual'] ?? null,
                    'clientes'       => $p['clientes'] ?? null,
                    'destacado'      => !empty($p['destacado']),
                    'incluye'        => $p['incluye'] ?? [],
                    'activo'         => true,
                    'orden'          => $orden += 10,
                    'empresas'       => 0,
                    'solo_lectura'   => true,
                ];
            }, (array) config('plataforma.planes', []));
        }

        $empresas = Schema::hasTable('plataforma_suscripciones')
            ? DB::table('plataforma_suscripciones')->select('plan_id', DB::raw('count(*) as t'))->groupBy('plan_id')->pluck('t', 'plan_id')
            : collect();

        return DB::table('plataforma_planes')->orderBy('orden')->orderBy('id')->get()
            ->map(fn ($p) => self::publico($p) + [
                'id'           => (int) $p->id,
                'activo'       => (bool) $p->activo,
                'orden'        => (int) $p->orden,
                'empresas'     => (int) ($empresas[$p->id] ?? 0),
                'solo_lectura' => false,
            ])
            ->all();
    }

    /** Un plan por id, tal como sale de la base. */
    public static function buscar(?int $id): ?object
    {
        if (!$id || !self::enBase()) {
            return null;
        }

        return DB::table('plataforma_planes')->where('id', $id)->first();
    }

    /** El precio de lista del plan para ese ciclo. */
    public static function precioDe(?object $plan, string $ciclo): ?float
    {
        if (!$plan) {
            return null;
        }

        $precio = $ciclo === 'anual' ? $plan->precio_anual : $plan->precio_mensual;

        return $precio === null ? null : (float) $precio;
    }

    /** Lo que se muestra de un plan, con las mismas claves que el config. */
    private static function publico(object $p): array
    {
        return [
            'clave'          => $p->clave,
            'nombre'         => $p->nombre,
            'para'           => $p->para,
            'precio_mensual' => $p->precio_mensual === null ? null : (float) $p->precio_mensual,
            'precio_anual'   => $p->precio_anual === null ? null : (float) $p->precio_anual,
            'clientes'       => $p->clientes === null ? null : (int) $p->clientes,
            'destacado'      => (bool) $p->destacado,
            'incluye'        => json_decode((string) $p->incluye, true) ?: [],
        ];
    }
}
