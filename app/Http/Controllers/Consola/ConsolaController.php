<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Services\Plataforma\Bitacora;
use App\Services\Plataforma\FacturacionDeLaPlataforma;
use App\Services\Plataforma\PanoramaDeEmpresas;
use App\Services\Plataforma\PlanesDeLaPlataforma;
use App\Services\Plataforma\SuscripcionDeEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consola de Netvula: el tablero, las empresas y la bitácora.
 *
 * Todo lo de aquí cruza empresas a propósito. Por eso vive en su propia
 * dirección (admin.netvula.com) y detrás del middleware 'consola': hace falta
 * una sesión de `plataforma_usuarios`, que no tiene nada que ver con los
 * usuarios ni los perfiles de las empresas. Desde el panel de una empresa
 * estas rutas ni siquiera existen.
 */
class ConsolaController extends Controller
{
    /** GET /api/consola/tablero */
    public function tablero(Request $request): JsonResponse
    {
        $filas = PanoramaDeEmpresas::filas(true, $request->boolean('refrescar'));

        $empresas = [
            'total'      => count($filas),
            'activas'    => count(array_filter($filas, fn ($e) => $e['activa'] && !$e['suspendida'])),
            'en_prueba'  => count(array_filter($filas, fn ($e) => ($e['suscripcion']['estado'] ?? null) === 'prueba')),
            'suspendidas'=> count(array_filter($filas, fn ($e) => $e['suspendida'])),
            'sin_confirmar' => count(array_filter($filas, fn ($e) => !$e['confirmada'])),
            'en_mora'    => count(array_filter($filas, fn ($e) => ($e['suscripcion']['estado'] ?? null) === 'en_mora')),
        ];

        $clientes = [
            'total'       => array_sum(array_column(array_column($filas, 'clientes'), 'total')),
            'activos'     => array_sum(array_column(array_column($filas, 'clientes'), 'activos')),
            'suspendidos' => array_sum(array_column(array_column($filas, 'clientes'), 'suspendidos')),
            'retirados'   => array_sum(array_column(array_column($filas, 'clientes'), 'retirados')),
        ];

        // Ingreso mensual recurrente: lo anual se prorratea a doce, así el
        // número es comparable mes a mes.
        $mrr = 0.0;
        $conPlan = 0;

        foreach ($filas as $e) {
            $s = $e['suscripcion'];

            if (!$s || !$s['plan_id'] || !in_array($s['estado'], ['al_dia', 'en_mora'], true) || $s['precio'] === null) {
                continue;
            }

            $conPlan++;
            $mrr += $s['ciclo'] === 'anual' ? ((float) $s['precio']) / 12 : (float) $s['precio'];
        }

        return standardApiReponse('OK', [
            'empresas'  => $empresas,
            'clientes'  => $clientes,
            'red' => [
                'con_olt'      => count(array_filter($filas, fn ($e) => $e['olts'] > 0)),
                'olts'         => array_sum(array_column($filas, 'olts')),
                'con_mikrotik' => count(array_filter($filas, fn ($e) => $e['routers'] > 0)),
                'routers'      => array_sum(array_column($filas, 'routers')),
                'con_tr069'    => count(array_filter($filas, fn ($e) => ($e['tr069'] ?? 0) > 0)),
                'equipos_tr069'=> array_sum(array_map(fn ($e) => (int) ($e['tr069'] ?? 0), $filas)),
                'acs_sin_respuesta' => count(array_filter($filas, fn ($e) => $e['tr069'] === null)),
                'lineas_wa'    => array_sum(array_column($filas, 'lineas_wa')),
                'lineas_wa_ok' => array_sum(array_column($filas, 'lineas_wa_ok')),
                'con_mailjet'  => count(array_filter($filas, fn ($e) => $e['mailjet'])),
            ],
            'ingresos' => [
                'moneda'          => config('plataforma.moneda', 'COP'),
                'mrr'             => round($mrr, 2),
                'anual_estimado'  => round($mrr * 12, 2),
                'empresas_con_plan' => $conPlan,
                'sin_plan'        => count(array_filter($filas, fn ($e) => empty($e['suscripcion']['plan_id']))),
            ],
            'crecimiento' => [
                'empresas' => PanoramaDeEmpresas::crecimientoDeEmpresas(12),
                'clientes' => PanoramaDeEmpresas::crecimientoDeClientes(12),
            ],
            'avisos' => SuscripcionDeEmpresa::hayTablas()
                ? FacturacionDeLaPlataforma::avisos()
                : ['por_vencer' => [], 'en_mora' => []],
            'migrada' => SuscripcionDeEmpresa::hayTablas(),
        ], 0, JsonResponse::HTTP_OK);
    }

    /** GET /api/consola/empresas?q=&tr069=1 */
    public function empresas(Request $request): JsonResponse
    {
        $filas = PanoramaDeEmpresas::filas($request->boolean('tr069'), $request->boolean('refrescar'));
        $q     = trim((string) $request->query('q', ''));

        if ($q !== '') {
            $aguja = mb_strtolower($q);
            $filas = array_values(array_filter($filas, function ($e) use ($aguja) {
                foreach (['nombre', 'subdominio', 'nit', 'email', 'slug'] as $campo) {
                    if (str_contains(mb_strtolower((string) $e[$campo]), $aguja)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return standardApiReponse('OK', ['empresas' => $filas, 'migrada' => SuscripcionDeEmpresa::hayTablas()], 0, JsonResponse::HTTP_OK);
    }

    /** GET /api/consola/empresas/{id} */
    public function empresa(int $id): JsonResponse
    {
        $detalle = PanoramaDeEmpresas::detalle($id);

        if (!$detalle) {
            return standardApiReponse('Esa empresa no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        $detalle['cobros']    = $this->cobrosDe($id);
        $detalle['creditos']  = Schema::hasTable('plataforma_creditos')
            ? DB::table('plataforma_creditos')->where('company_id', $id)->orderByDesc('id')->limit(50)->get()
            : [];
        $detalle['referidos'] = Schema::hasTable('plataforma_referidos')
            ? DB::table('plataforma_referidos as r')
                ->join('companies as e', 'e.id', '=', 'r.referida_company_id')
                ->where('r.referidor_company_id', $id)
                ->orderByDesc('r.id')
                ->get(['r.id', 'r.estado', 'r.credito_otorgado', 'r.acreditado_en', 'r.created_at', 'e.id as empresa_id', 'e.name as empresa'])
            : [];
        $detalle['planes']    = PlanesDeLaPlataforma::todos();

        return standardApiReponse('OK', $detalle, 0, JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/consola/empresas/{id}/suspender  { motivo }
     *
     * Suspender = el equipo de esa empresa no entra al panel ni a su API.
     * NO toca a sus clientes: su internet sigue andando, el portal de clientes
     * sigue abierto y las tareas automáticas (cortes por mora, facturación de
     * la empresa a sus clientes) siguen corriendo. Es un corte comercial con
     * Netvula, no un corte de servicio.
     */
    public function suspender(int $id, Request $request): JsonResponse
    {
        if (!Schema::hasColumn('companies', 'plataforma_suspendida')) {
            return standardApiReponse('Falta correr la migración de la consola.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        $request->validate(['motivo' => 'required|string|max:255']);

        $empresa = DB::table('companies')->where('id', $id)->first(['id', 'name']);

        if (!$empresa) {
            return standardApiReponse('Esa empresa no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        DB::table('companies')->where('id', $id)->update([
            'plataforma_suspendida'        => 1,
            'plataforma_suspendida_motivo' => $request->input('motivo'),
            'updated_at'                   => now(),
        ]);

        if (SuscripcionDeEmpresa::hayTablas()) {
            SuscripcionDeEmpresa::asegurar($id);
            DB::table('plataforma_suscripciones')->where('company_id', $id)->update(['estado' => 'suspendida', 'updated_at' => now()]);
        }

        Bitacora::anotar('empresa.suspendida', $id, ['motivo' => $request->input('motivo')], 'empresa', $id);

        return standardApiReponse(
            'Listo: el equipo de ' . $empresa->name . ' no puede entrar al panel. Sus clientes no se ven afectados.',
            null, 0, JsonResponse::HTTP_OK
        );
    }

    /** POST /api/consola/empresas/{id}/reactivar */
    public function reactivar(int $id): JsonResponse
    {
        if (!Schema::hasColumn('companies', 'plataforma_suspendida')) {
            return standardApiReponse('Falta correr la migración de la consola.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        $empresa = DB::table('companies')->where('id', $id)->first(['id', 'name']);

        if (!$empresa) {
            return standardApiReponse('Esa empresa no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        DB::table('companies')->where('id', $id)->update([
            'plataforma_suspendida'        => 0,
            'plataforma_suspendida_motivo' => null,
            'updated_at'                   => now(),
        ]);

        if (SuscripcionDeEmpresa::hayTablas()) {
            // Vuelve al estado que corresponda: si le quedan cobros vencidos
            // sigue en mora, si no, al día.
            $enMora = DB::table('plataforma_cobros')
                ->where('company_id', $id)->where('estado', 'pendiente')
                ->where('vence', '<', now()->toDateString())->exists();

            DB::table('plataforma_suscripciones')->where('company_id', $id)
                ->update(['estado' => $enMora ? 'en_mora' : 'al_dia', 'updated_at' => now()]);
        }

        Bitacora::anotar('empresa.reactivada', $id, [], 'empresa', $id);

        return standardApiReponse('Listo: ' . $empresa->name . ' ya puede entrar al panel.', null, 0, JsonResponse::HTTP_OK);
    }

    /** GET /api/consola/bitacora?empresa=&accion=&pagina= */
    public function bitacora(Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_bitacora')) {
            return standardApiReponse('OK', ['items' => [], 'total' => 0, 'acciones' => []], 0, JsonResponse::HTTP_OK);
        }

        $porPagina = min(100, max(10, (int) $request->query('por_pagina', 30)));
        $pagina    = max(1, (int) $request->query('pagina', 1));

        $base = DB::table('plataforma_bitacora as b')
            ->leftJoin('companies as e', 'e.id', '=', 'b.company_id')
            ->when($request->filled('empresa'), fn ($q) => $q->where('b.company_id', (int) $request->query('empresa')))
            ->when($request->filled('accion'), fn ($q) => $q->where('b.accion', $request->query('accion')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('b.created_at', '>=', $request->query('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('b.created_at', '<=', $request->query('hasta')));

        $total = (clone $base)->count();

        $items = $base->orderByDesc('b.id')
            ->forPage($pagina, $porPagina)
            ->get(['b.id', 'b.usuario', 'b.accion', 'b.company_id', 'b.entidad', 'b.entidad_id', 'b.detalle', 'b.ip', 'b.created_at', 'e.name as empresa'])
            ->map(function ($f) {
                $f->detalle = $f->detalle ? json_decode((string) $f->detalle, true) : null;

                return $f;
            });

        return standardApiReponse('OK', [
            'items'    => $items,
            'total'    => $total,
            'pagina'   => $pagina,
            'acciones' => DB::table('plataforma_bitacora')->distinct()->orderBy('accion')->pluck('accion'),
        ], 0, JsonResponse::HTTP_OK);
    }

    /** Los cobros de una empresa, con sus pagos. */
    private function cobrosDe(int $companyId): array
    {
        if (!Schema::hasTable('plataforma_cobros')) {
            return [];
        }

        $cobros = DB::table('plataforma_cobros')->where('company_id', $companyId)->orderByDesc('periodo_inicio')->get();

        $pagos = DB::table('plataforma_pagos')
            ->whereIn('cobro_id', $cobros->pluck('id'))
            ->orderBy('fecha')
            ->get(['id', 'cobro_id', 'monto', 'fecha', 'metodo', 'referencia', 'comprobante', 'nota'])
            ->groupBy('cobro_id');

        return $cobros->map(function ($c) use ($pagos) {
            $c->detalle = $c->detalle ? json_decode((string) $c->detalle, true) : [];
            $c->saldo   = round((float) $c->total - (float) $c->pagado, 2);
            $c->vencido = $c->estado === 'pendiente' && $c->vence < now()->toDateString();
            $c->pagos   = array_values(($pagos[$c->id] ?? collect())->all());

            return $c;
        })->all();
    }
}
