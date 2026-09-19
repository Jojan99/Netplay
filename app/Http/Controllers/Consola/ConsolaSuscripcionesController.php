<?php

namespace App\Http\Controllers\Consola;

use App\Http\Controllers\Controller;
use App\Services\Plataforma\Bitacora;
use App\Services\Plataforma\CalculadoraDeCobro;
use App\Services\Plataforma\Cupones;
use App\Services\Plataforma\FacturacionDeLaPlataforma;
use App\Services\Plataforma\PlanesDeLaPlataforma;
use App\Services\Plataforma\Referidos;
use App\Services\Plataforma\SuscripcionDeEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La suscripción de cada empresa con Netvula y su facturación.
 *
 * Nada de datos de tarjeta: el método de pago es sólo el dato de cómo paga
 * (transferencia, efectivo, pasarela) y el comprobante es un archivo.
 */
class ConsolaSuscripcionesController extends Controller
{
    /** PUT /api/consola/empresas/{id}/suscripcion */
    public function guardar(int $id, Request $request): JsonResponse
    {
        if (!SuscripcionDeEmpresa::hayTablas()) {
            return $this->sinMigrar();
        }

        $datos = $request->validate([
            'plan_id'             => 'nullable|integer|exists:plataforma_planes,id',
            'ciclo'               => 'required|in:mensual,anual',
            'precio_pactado'      => 'nullable|numeric|min:0|max:99999999',
            'estado'              => 'required|in:prueba,al_dia,en_mora,suspendida,cancelada',
            'metodo_pago'         => 'nullable|in:transferencia,efectivo,pasarela',
            'inicio'              => 'nullable|date',
            'prueba_hasta'        => 'nullable|date',
            'proxima_facturacion' => 'nullable|date',
            'notas'               => 'nullable|string|max:2000',
        ]);

        $antes = SuscripcionDeEmpresa::asegurar($id);

        if (!$antes) {
            return standardApiReponse('Esa empresa no existe.', null, 1, JsonResponse::HTTP_NOT_FOUND);
        }

        DB::table('plataforma_suscripciones')->where('company_id', $id)->update([
            'plan_id'             => $datos['plan_id'] ?? null,
            'ciclo'               => $datos['ciclo'],
            'precio_pactado'      => $datos['precio_pactado'] ?? null,
            'estado'              => $datos['estado'],
            'metodo_pago'         => $datos['metodo_pago'] ?? null,
            'inicio'              => $datos['inicio'] ?? $antes->inicio,
            'prueba_hasta'        => $datos['prueba_hasta'] ?? null,
            'proxima_facturacion' => $datos['proxima_facturacion'] ?? null,
            'notas'               => $datos['notas'] ?? null,
            'updated_at'          => now(),
        ]);

        $plan = PlanesDeLaPlataforma::buscar($datos['plan_id'] ?? null);

        Bitacora::anotar('suscripcion.editada', $id, [
            'antes' => ['plan' => $antes->plan_id, 'ciclo' => $antes->ciclo, 'precio' => $antes->precio_pactado, 'estado' => $antes->estado],
            'ahora' => ['plan' => $datos['plan_id'] ?? null, 'ciclo' => $datos['ciclo'], 'precio' => $datos['precio_pactado'] ?? null, 'estado' => $datos['estado']],
        ], 'suscripcion', $antes->id);

        return standardApiReponse(
            'Suscripción guardada' . ($plan ? ': plan ' . $plan->nombre . ' (' . $datos['ciclo'] . ').' : '.'),
            null, 0, JsonResponse::HTTP_OK
        );
    }

    /** POST /api/consola/empresas/{id}/cupon  { codigo } */
    public function aplicarCupon(int $id, Request $request): JsonResponse
    {
        if (!Cupones::hayTabla() || !SuscripcionDeEmpresa::hayTablas()) {
            return $this->sinMigrar();
        }

        $request->validate(['codigo' => 'required|string|max:40']);

        $suscripcion = SuscripcionDeEmpresa::asegurar($id);
        $plan        = PlanesDeLaPlataforma::buscar($suscripcion?->plan_id ? (int) $suscripcion->plan_id : null);

        $revision = Cupones::revisar(
            $request->input('codigo'),
            $id,
            $plan?->clave,
            $suscripcion?->ciclo
        );

        if (!$revision['ok']) {
            return standardApiReponse($revision['motivo'], null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        Cupones::aplicarASuscripcion($revision['cupon'], $id, 'consola');

        Bitacora::anotar('cupon.aplicado', $id, ['codigo' => $revision['cupon']->codigo], 'cupon', $revision['cupon']->id);

        return standardApiReponse(
            'Cupón ' . $revision['cupon']->codigo . ' aplicado. Se va a ver en el próximo cobro.',
            CalculadoraDeCobro::simular($id), 0, JsonResponse::HTTP_OK
        );
    }

    /** DELETE /api/consola/empresas/{id}/cupon */
    public function quitarCupon(int $id): JsonResponse
    {
        if (!SuscripcionDeEmpresa::hayTablas()) {
            return $this->sinMigrar();
        }

        Cupones::quitarDeSuscripcion($id);
        Bitacora::anotar('cupon.quitado', $id, [], 'suscripcion', $id);

        return standardApiReponse('Cupón quitado.', null, 0, JsonResponse::HTTP_OK);
    }

    /** POST /api/consola/empresas/{id}/credito  { monto, nota } */
    public function credito(int $id, Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_creditos')) {
            return $this->sinMigrar();
        }

        $datos = $request->validate([
            // Negativo se permite: sirve para corregir un crédito mal cargado.
            'monto' => 'required|numeric|not_in:0|min:-99999999|max:99999999',
            'nota'  => 'required|string|max:255',
        ]);

        $saldo = SuscripcionDeEmpresa::credito($id);

        if ($datos['monto'] < 0 && abs($datos['monto']) > $saldo) {
            return standardApiReponse(
                'No se puede quitar más crédito del que tiene. Saldo actual: $' . number_format($saldo, 0, ',', '.') . '.',
                null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        DB::table('plataforma_creditos')->insert([
            'company_id' => $id,
            'monto'      => $datos['monto'],
            'motivo'     => 'Ajuste manual',
            'nota'       => $datos['nota'],
            'user_id'    => Bitacora::idActual(),
            'created_at' => now(),
        ]);

        Bitacora::anotar('credito.ajustado', $id, $datos, 'credito', $id);

        return standardApiReponse(
            'Crédito ajustado. Nuevo saldo: $' . number_format(SuscripcionDeEmpresa::credito($id), 0, ',', '.') . '.',
            ['saldo' => SuscripcionDeEmpresa::credito($id)], 0, JsonResponse::HTTP_OK
        );
    }

    /** GET /api/consola/empresas/{id}/cobros/simular?periodo=YYYY-MM-DD */
    public function simular(int $id, Request $request): JsonResponse
    {
        if (!SuscripcionDeEmpresa::hayTablas()) {
            return $this->sinMigrar();
        }

        $request->validate(['periodo' => 'nullable|date']);

        $calculo = CalculadoraDeCobro::simular($id, $request->query('periodo'));

        return standardApiReponse(
            $calculo['ok'] ? 'OK' : $calculo['motivo'],
            $calculo,
            $calculo['ok'] ? 0 : 1,
            JsonResponse::HTTP_OK
        );
    }

    /** POST /api/consola/empresas/{id}/cobros  { periodo? } */
    public function generar(int $id, Request $request): JsonResponse
    {
        if (!SuscripcionDeEmpresa::hayTablas()) {
            return $this->sinMigrar();
        }

        $request->validate(['periodo' => 'nullable|date']);

        $r = FacturacionDeLaPlataforma::generar($id, $request->input('periodo'));

        if (!$r['ok']) {
            return standardApiReponse($r['motivo'], $r['detalle'], 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return standardApiReponse(
            'Cobro generado por $' . number_format($r['detalle']['total'], 0, ',', '.') . '.',
            ['cobro_id' => $r['cobro_id'], 'detalle' => $r['detalle']],
            0, JsonResponse::HTTP_OK
        );
    }

    /**
     * POST /api/consola/cobros/{id}/pagos
     *
     * El comprobante es una imagen o un PDF; se guarda en disco, no en la base.
     */
    public function registrarPago(int $id, Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_pagos')) {
            return $this->sinMigrar();
        }

        $datos = $request->validate([
            'monto'       => 'required|numeric|min:1|max:99999999',
            'fecha'       => 'required|date',
            'metodo'      => 'required|in:transferencia,efectivo,pasarela',
            'referencia'  => 'nullable|string|max:80',
            'nota'        => 'nullable|string|max:255',
            'comprobante' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $ruta = null;

        if ($request->hasFile('comprobante')) {
            $ruta = $request->file('comprobante')->store('plataforma/comprobantes', 'local');
        }

        $r = FacturacionDeLaPlataforma::registrarPago(
            $id,
            (float) $datos['monto'],
            $datos['fecha'],
            $datos['metodo'],
            $datos['referencia'] ?? null,
            $ruta,
            $datos['nota'] ?? null
        );

        if (!$r['ok']) {
            return standardApiReponse($r['motivo'], null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return standardApiReponse(
            $r['saldo'] > 0
                ? 'Pago registrado. Queda un saldo de $' . number_format($r['saldo'], 0, ',', '.') . '.'
                : 'Pago registrado. El cobro quedó saldado.',
            ['saldo' => $r['saldo']], 0, JsonResponse::HTTP_OK
        );
    }

    /** POST /api/consola/cobros/{id}/anular  { motivo } */
    public function anular(int $id, Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_cobros')) {
            return $this->sinMigrar();
        }

        $request->validate(['motivo' => 'required|string|max:255']);

        $r = FacturacionDeLaPlataforma::anular($id, $request->input('motivo'));

        return $r['ok']
            ? standardApiReponse('Cobro anulado.', null, 0, JsonResponse::HTTP_OK)
            : standardApiReponse($r['motivo'], null, 1, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** GET /api/consola/cobros?estado=&empresa= */
    public function cobros(Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_cobros')) {
            return standardApiReponse('OK', ['items' => [], 'resumen' => []], 0, JsonResponse::HTTP_OK);
        }

        $items = DB::table('plataforma_cobros as c')
            ->join('companies as e', 'e.id', '=', 'c.company_id')
            ->when($request->filled('empresa'), fn ($q) => $q->where('c.company_id', (int) $request->query('empresa')))
            ->when($request->filled('estado'), fn ($q) => $q->where('c.estado', $request->query('estado')))
            ->when($request->boolean('vencidos'), fn ($q) => $q->where('c.estado', 'pendiente')->where('c.vence', '<', now()->toDateString()))
            ->orderByDesc('c.periodo_inicio')->orderBy('e.name')
            ->limit(500)
            ->get(['c.id', 'c.company_id', 'e.name as empresa', 'c.periodo_inicio', 'c.periodo_fin', 'c.ciclo',
                   'c.precio_lista', 'c.descuento', 'c.cupon_codigo', 'c.credito_aplicado', 'c.total', 'c.pagado',
                   'c.vence', 'c.estado', 'c.pagado_en'])
            ->map(function ($c) {
                $c->saldo   = round((float) $c->total - (float) $c->pagado, 2);
                $c->vencido = $c->estado === 'pendiente' && $c->vence < now()->toDateString();

                return $c;
            });

        return standardApiReponse('OK', [
            'items' => $items,
            'resumen' => [
                'cobrado'   => round((float) DB::table('plataforma_cobros')->where('estado', 'pagado')->sum('total'), 2),
                'pendiente' => round((float) DB::table('plataforma_cobros')->where('estado', 'pendiente')->sum(DB::raw('total - pagado')), 2),
                'en_mora'   => round((float) DB::table('plataforma_cobros')->where('estado', 'pendiente')->where('vence', '<', now()->toDateString())->sum(DB::raw('total - pagado')), 2),
            ],
            'avisos' => FacturacionDeLaPlataforma::avisos(),
        ], 0, JsonResponse::HTTP_OK);
    }

    /** POST /api/consola/cobros/marcar-moras */
    public function marcarMoras(): JsonResponse
    {
        if (!SuscripcionDeEmpresa::hayTablas()) {
            return $this->sinMigrar();
        }

        $cuantas = FacturacionDeLaPlataforma::marcarMoras();

        Bitacora::anotar('moras.marcadas', null, ['cuantas' => $cuantas]);

        return standardApiReponse($cuantas . ' suscripción(es) pasaron a "en mora".', ['cuantas' => $cuantas], 0, JsonResponse::HTTP_OK);
    }

    /** GET /api/consola/referidos */
    public function referidos(): JsonResponse
    {
        if (!Referidos::hayTablas()) {
            return standardApiReponse('OK', ['ranking' => [], 'referidos' => [], 'ajustes' => Referidos::ajustes(), 'creditos' => []], 0, JsonResponse::HTTP_OK);
        }

        $ranking = DB::table('plataforma_referidos as r')
            ->join('companies as e', 'e.id', '=', 'r.referidor_company_id')
            ->groupBy('r.referidor_company_id', 'e.name')
            ->orderByDesc('total')
            ->get([
                'r.referidor_company_id as company_id', 'e.name as empresa',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(r.estado = 'activo') as activos"),
                DB::raw('SUM(r.credito_otorgado) as credito'),
            ]);

        $referidos = DB::table('plataforma_referidos as r')
            ->join('companies as a', 'a.id', '=', 'r.referidor_company_id')
            ->join('companies as b', 'b.id', '=', 'r.referida_company_id')
            ->orderByDesc('r.id')
            ->limit(300)
            ->get(['r.id', 'r.codigo', 'r.estado', 'r.credito_otorgado', 'r.acreditado_en', 'r.created_at',
                   'a.id as referidor_id', 'a.name as referidor', 'b.id as referida_id', 'b.name as referida']);

        return standardApiReponse('OK', [
            'ranking'   => $ranking,
            'referidos' => $referidos,
            'ajustes'   => Referidos::ajustes(),
            'creditos'  => [
                'otorgados' => round((float) DB::table('plataforma_creditos')->where('monto', '>', 0)->sum('monto'), 2),
                'consumidos'=> round(abs((float) DB::table('plataforma_creditos')->where('monto', '<', 0)->sum('monto')), 2),
                'saldo'     => round((float) DB::table('plataforma_creditos')->sum('monto'), 2),
            ],
        ], 0, JsonResponse::HTTP_OK);
    }

    /** PUT /api/consola/referidos/ajustes */
    public function guardarAjustesReferidos(Request $request): JsonResponse
    {
        if (!Schema::hasTable('plataforma_ajustes')) {
            return $this->sinMigrar();
        }

        $datos = $request->validate([
            'activo'                      => 'required|boolean',
            'beneficio_referido.tipo'     => 'required|in:porcentaje,monto',
            'beneficio_referido.valor'    => 'required|numeric|min:0|max:99999999',
            'beneficio_referido.periodos' => 'required|integer|min:1|max:36',
            'beneficio_referidor.tipo'    => 'required|in:porcentaje,monto',
            'beneficio_referidor.valor'   => 'required|numeric|min:0|max:99999999',
            'acreditar_en'                => 'required|in:registro,primer_pago',
        ]);

        Referidos::guardarAjustes($datos);
        Bitacora::anotar('referidos.ajustes', null, $datos);

        return standardApiReponse('Programa de referidos guardado.', Referidos::ajustes(), 0, JsonResponse::HTTP_OK);
    }

    private function sinMigrar(): JsonResponse
    {
        return standardApiReponse(
            'Falta correr la migración de la consola para usar esto.',
            null, 1, JsonResponse::HTTP_CONFLICT
        );
    }
}
