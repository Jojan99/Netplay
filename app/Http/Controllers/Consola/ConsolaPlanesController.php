<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Services\Plataforma\Bitacora;
use App\Services\Plataforma\PlanesDeLaPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los planes que vende Netvula, editables desde la consola.
 *
 * Cambiar el precio de un plan NO cambia lo que pagan las empresas que ya lo
 * tienen: cada suscripción guarda su precio pactado. Para pasarlas al precio
 * nuevo hay que decirlo explícitamente, y antes se avisa a cuántas afecta.
 */
class ConsolaPlanesController extends Controller
{
    private const REGLAS = [
        'clave'          => 'required|string|max:40|regex:/^[a-z0-9_-]+$/',
        'nombre'         => 'required|string|max:80',
        'para'           => 'nullable|string|max:160',
        'precio_mensual' => 'nullable|numeric|min:0|max:99999999',
        'precio_anual'   => 'nullable|numeric|min:0|max:99999999',
        'clientes'       => 'nullable|integer|min:1|max:1000000',
        'destacado'      => 'boolean',
        'incluye'        => 'array',
        'incluye.*'      => 'string|max:160',
        'activo'         => 'boolean',
        'orden'          => 'integer|min:0|max:9999',
    ];

    /** GET /api/consola/planes */
    public function index(): JsonResponse
    {
        return standardApiReponse('OK', [
            'planes'       => PlanesDeLaPlataforma::todos(),
            'moneda'       => config('plataforma.moneda', 'COP'),
            'prueba_dias'  => (int) config('plataforma.prueba_dias', 15),
            'nota_precios' => config('plataforma.nota_precios', ''),
            'en_base'      => PlanesDeLaPlataforma::enBase(),
        ], 0, JsonResponse::HTTP_OK);
    }

    /** POST /api/consola/planes */
    public function store(Request $request): JsonResponse
    {
        if (!PlanesDeLaPlataforma::enBase()) {
            return $this->sinMigrar();
        }

        $datos = $request->validate(self::REGLAS);

        if (DB::table('plataforma_planes')->where('clave', $datos['clave'])->exists()) {
            return standardApiReponse('Ya hay un plan con esa clave.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        $id = DB::table('plataforma_planes')->insertGetId(
            $this->fila($datos) + ['created_at' => now(), 'updated_at' => now()]
        );

        $this->anotarPrecio($id, $datos, 'Plan creado');

        Bitacora::anotar('plan.creado', null, ['plan' => $datos['nombre'], 'clave' => $datos['clave']], 'plan', $id);

        return standardApiReponse('Plan creado.', ['id' => $id], 0, JsonResponse::HTTP_OK);
    }

    /** PUT /api/consola/planes/{id} */
    public function update(int $id, Request $request): JsonResponse
    {
        if (!PlanesDeLaPlataforma::enBase()) {
            return $this->sinMigrar();
        }

        $plan = DB::table('plataforma_planes')->where('id', $id)->first();

        if (!$plan) {
            return standardApiReponse('Ese plan no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        $reglas = self::REGLAS;
        $reglas['clave'] = 'required|string|max:40|regex:/^[a-z0-9_-]+$/';
        $datos = $request->validate($reglas);

        if (DB::table('plataforma_planes')->where('clave', $datos['clave'])->where('id', '!=', $id)->exists()) {
            return standardApiReponse('Ya hay otro plan con esa clave.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        $cambioDePrecio = (float) $plan->precio_mensual !== (float) ($datos['precio_mensual'] ?? 0)
            || (float) $plan->precio_anual !== (float) ($datos['precio_anual'] ?? 0);

        DB::table('plataforma_planes')->where('id', $id)->update($this->fila($datos) + ['updated_at' => now()]);

        if ($cambioDePrecio) {
            $this->anotarPrecio($id, $datos, $request->input('motivo_precio'));
        }

        Bitacora::anotar('plan.editado', null, [
            'plan'  => $datos['nombre'],
            'antes' => ['mensual' => $plan->precio_mensual, 'anual' => $plan->precio_anual],
            'ahora' => ['mensual' => $datos['precio_mensual'] ?? null, 'anual' => $datos['precio_anual'] ?? null],
        ], 'plan', $id);

        return standardApiReponse(
            $cambioDePrecio
                ? 'Plan guardado. Las empresas que ya lo tienen siguen con su precio pactado.'
                : 'Plan guardado.',
            ['afectadas' => $cambioDePrecio ? $this->empresasDelPlan($id)->count() : 0],
            0, JsonResponse::HTTP_OK
        );
    }

    /** DELETE /api/consola/planes/{id} */
    public function destroy(int $id): JsonResponse
    {
        if (!PlanesDeLaPlataforma::enBase()) {
            return $this->sinMigrar();
        }

        $enUso = Schema::hasTable('plataforma_suscripciones')
            ? DB::table('plataforma_suscripciones')->where('plan_id', $id)->count()
            : 0;

        if ($enUso > 0) {
            return standardApiReponse(
                'No se puede borrar: ' . $enUso . ' empresa(s) lo tienen asignado. Desactivalo para que deje de aparecer en la página.',
                null, 1, JsonResponse::HTTP_CONFLICT
            );
        }

        $nombre = DB::table('plataforma_planes')->where('id', $id)->value('nombre');
        DB::table('plataforma_planes')->where('id', $id)->delete();

        Bitacora::anotar('plan.borrado', null, ['plan' => $nombre], 'plan', $id);

        return standardApiReponse('Plan borrado.', null, 0, JsonResponse::HTTP_OK);
    }

    /** GET /api/consola/planes/{id}/precios — el historial de precios. */
    public function precios(int $id): JsonResponse
    {
        if (!Schema::hasTable('plataforma_plan_precios')) {
            return standardApiReponse('OK', ['historial' => [], 'afectadas' => []], 0, JsonResponse::HTTP_OK);
        }

        return standardApiReponse('OK', [
            'historial' => DB::table('plataforma_plan_precios as h')
                ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
                ->where('h.plan_id', $id)->orderByDesc('h.id')
                ->get(['h.id', 'h.precio_mensual', 'h.precio_anual', 'h.desde', 'h.motivo', 'u.username as usuario']),
            'afectadas' => $this->empresasDelPlan($id)->values(),
        ], 0, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/consola/planes/{id}/aplicar-precio  { empresas?: number[] }
     *
     * Pasa al precio nuevo a las empresas del plan: les borra el precio
     * pactado y desde el próximo cobro pagan el de lista. Sin lista de
     * empresas, a todas las del plan.
     */
    public function aplicarPrecio(int $id, Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_suscripciones')) {
            return $this->sinMigrar();
        }

        $request->validate(['empresas' => 'array', 'empresas.*' => 'integer']);

        $plan = DB::table('plataforma_planes')->where('id', $id)->first();

        if (!$plan) {
            return standardApiReponse('Ese plan no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        $query = DB::table('plataforma_suscripciones')->where('plan_id', $id);

        if ($request->filled('empresas')) {
            $query->whereIn('company_id', $request->input('empresas'));
        }

        $empresas = $query->pluck('company_id');
        $cuantas  = $query->update(['precio_pactado' => null, 'updated_at' => now()]);

        Bitacora::anotar('plan.precio_aplicado', null, [
            'plan' => $plan->nombre, 'empresas' => $empresas->all(), 'cuantas' => $cuantas,
        ], 'plan', $id);

        return standardApiReponse(
            $cuantas === 0
                ? 'Ninguna empresa quedó con el precio nuevo.'
                : $cuantas . ' empresa(s) pasan al precio de lista desde su próximo cobro.',
            ['afectadas' => $cuantas], 0, JsonResponse::HTTP_OK
        );
    }

    // ── Auxiliares ────────────────────────────────────────────────────────

    /** Empresas con ese plan y qué precio están pagando hoy. */
    private function empresasDelPlan(int $planId)
    {
        if (!Schema::hasTable('plataforma_suscripciones')) {
            return collect();
        }

        return DB::table('plataforma_suscripciones as s')
            ->join('companies as e', 'e.id', '=', 's.company_id')
            ->where('s.plan_id', $planId)
            ->orderBy('e.name')
            ->get(['s.company_id', 'e.name as empresa', 's.ciclo', 's.precio_pactado', 's.estado']);
    }

    private function fila(array $d): array
    {
        return [
            'clave'          => $d['clave'],
            'nombre'         => $d['nombre'],
            'para'           => $d['para'] ?? null,
            'precio_mensual' => $d['precio_mensual'] ?? null,
            'precio_anual'   => $d['precio_anual'] ?? null,
            'clientes'       => $d['clientes'] ?? null,
            'destacado'      => !empty($d['destacado']),
            'incluye'        => json_encode(array_values($d['incluye'] ?? []), JSON_UNESCAPED_UNICODE),
            'activo'         => $d['activo'] ?? true,
            'orden'          => $d['orden'] ?? 0,
        ];
    }

    private function anotarPrecio(int $planId, array $datos, ?string $motivo): void
    {
        if (!Schema::hasTable('plataforma_plan_precios')) {
            return;
        }

        DB::table('plataforma_plan_precios')->insert([
            'plan_id'        => $planId,
            'precio_mensual' => $datos['precio_mensual'] ?? null,
            'precio_anual'   => $datos['precio_anual'] ?? null,
            'desde'          => now(),
            'motivo'         => $motivo,
            'user_id'        => Bitacora::idActual(),
            'created_at'     => now(),
        ]);
    }

    private function sinMigrar(): JsonResponse
    {
        return standardApiReponse(
            'Los planes todavía se leen de la configuración. Ejecute la migración de la consola para poder editarlos.',
            null, 1, JsonResponse::HTTP_CONFLICT
        );
    }
}
