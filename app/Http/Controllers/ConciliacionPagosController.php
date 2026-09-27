<?php

namespace App\Http\Controllers;

use App\Services\Facturacion\AplicarPagoALasFacturas;
use App\Services\Facturacion\CruceDePagosPorNombre;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conciliación de pagos: una lista de «nombre y valor» que llegó de afuera (un cuaderno,
 * un extracto, un chat) se cruza contra los clientes, se revisa y se aplica a sus facturas.
 *
 * Tres pasos, y sólo el último escribe:
 *   cruzar   → a qué cliente corresponde cada nombre (nada se guarda)
 *   simular  → qué facturas se pagarían y cuáles quedarían abonadas (nada se guarda)
 *   aplicar  → lo escribe, deja el «antes» de cada factura y una marca por pago
 */
class ConciliacionPagosController extends Controller
{
    private const MAX_FILAS = 600;

    private function empresa(): int
    {
        return (int) getSessionCompanyId();
    }

    /** 1) Leer la lista pegada y decir a quién corresponde cada nombre. */
    public function cruzar(Request $request): JsonResponse
    {
        $d = $request->validate(['texto' => 'required|string|max:80000']);

        $leido = CruceDePagosPorNombre::leer($d['texto']);

        if (!$leido['filas']) {
            return standardApiReponse('No pude leer ninguna línea. Cada una debe ser «NOMBRE   VALOR».', ['filas' => [], 'no_leidas' => $leido['no_leidas']], 1, JsonResponse::HTTP_OK);
        }
        if (count($leido['filas']) > self::MAX_FILAS) {
            return standardApiReponse('La lista tiene más de ' . self::MAX_FILAS . ' líneas. Divídala en dos.', null, 1, JsonResponse::HTTP_OK);
        }

        $filas = (new CruceDePagosPorNombre($this->empresa()))->cruzar($leido['filas']);

        return standardApiReponse('OK', ['filas' => $filas, 'no_leidas' => $leido['no_leidas']], 0, JsonResponse::HTTP_OK);
    }

    /** Buscar a mano a un cliente, para corregir una fila. */
    public function clientes(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return standardApiReponse('OK', (new CruceDePagosPorNombre($this->empresa()))->buscar($q), 0, JsonResponse::HTTP_OK);
    }

    /** 2) Qué pasaría, sin escribir nada. */
    public function simular(Request $request): JsonResponse
    {
        $d = $this->validar($request, false);

        $r = $this->correr($d, false, 'simulacion');

        return standardApiReponse('OK', $r, 0, JsonResponse::HTTP_OK);
    }

    /** 3) Aplicar de verdad. */
    public function aplicar(Request $request): JsonResponse
    {
        $d = $this->validar($request, true);

        // El «antes»: se toma con una simulación justo antes de escribir, así son
        // exactamente las facturas que se van a tocar y no otras.
        $plan = $this->correr($d, false, 'simulacion');
        $ids = collect($plan['filas'])->flatMap(fn ($f) => collect($f['movimientos'])->pluck('det_id'))->unique()->values()->all();

        \App\Services\Facturacion\LoteDePagos::abrir($this->empresa(), $d['lote'], getSessionUserId(), $d['metodo_id'], $d['orden'], $d['fecha'] ?? null, $d['titulo'] ?? null, $ids, 'web');

        $r = $this->correr($d, true, 'aplicado');

        \App\Services\Facturacion\LoteDePagos::cerrar($this->empresa(), $d['lote'], $r['resumen']);

        Log::info('[Conciliación] Pagos aplicados', [
            'empresa' => $this->empresa(), 'lote' => $d['lote'], 'por' => getSessionUserId(),
            'pagos' => $r['resumen']['pagos_aplicados'], 'aplicado' => $r['resumen']['aplicado'],
        ]);

        return standardApiReponse('Pagos aplicados', $r + ['lote' => $d['lote']], 0, JsonResponse::HTTP_OK);
    }

    /** Lo que ya se aplicó, para poder revisarlo después. */
    public function lotes(): JsonResponse
    {
        $filas = DB::table('conciliacion_pagos_lotes as l')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'l.user_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'l.payment_method_id')
            ->where('l.company_id', $this->empresa())
            ->orderByDesc('l.id')->limit(50)
            ->get(['l.lote', 'l.origen', 'l.titulo', 'l.orden', 'l.fecha_pago', 'l.pagos', 'l.aplicado', 'l.sin_aplicar', 'l.created_at', 'l.revertido_en',
                DB::raw("TRIM(CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,''))) as usuario"), 'pm.name as metodo']);

        return standardApiReponse('OK', $filas, 0, JsonResponse::HTTP_OK);
    }

    /** Qué aplicó un lote y qué pasaría si se deshace. No toca nada. */
    public function lote(string $lote): JsonResponse
    {
        $r = \App\Services\Facturacion\LoteDePagos::vistaPrevia($this->empresa(), $lote);

        return standardApiReponse($r['existe'] ? 'OK' : 'Ese lote no existe.', $r, $r['existe'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    /** Deshace un lote entero: las facturas vuelven a como estaban. Todo o nada. */
    public function revertir(string $lote): JsonResponse
    {
        $r = \App\Services\Facturacion\LoteDePagos::revertir($this->empresa(), $lote, getSessionUserId());

        if ($r['ok']) {
            Log::info('[Conciliación] Lote deshecho', ['empresa' => $this->empresa(), 'lote' => $lote, 'por' => getSessionUserId(), 'facturas' => $r['facturas'], 'monto' => $r['monto']]);
        }

        return standardApiReponse($r['message'], $r, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    // ── Piezas ────────────────────────────────────────────────────────────

    private function validar(Request $request, bool $aplicar): array
    {
        $d = $request->validate([
            'filas'            => 'required|array|min:1|max:' . self::MAX_FILAS,
            'filas.*.ref'      => 'required|string|max:20',
            'filas.*.cedula'   => 'required|string|max:30',
            'filas.*.nombre'   => 'nullable|string|max:255',
            'filas.*.valor'    => 'required|numeric|gt:0|max:100000000',
            // Quien vio el aviso de «posible duplicado» y decidió que es otro pago.
            'filas.*.forzar'   => 'nullable|boolean',
            'orden'            => 'required|in:antigua,exacta',
            'metodo_id'        => [$aplicar ? 'required' : 'nullable', 'integer', \Illuminate\Validation\Rule::exists('payment_methods', 'id')->where('company_id', $this->empresa())],
            'fecha'            => 'nullable|date|before_or_equal:today',
            'titulo'           => 'nullable|string|max:120',
            // Lo genera la pantalla una vez por lista: es lo que impide aplicar dos veces.
            'lote'             => [$aplicar ? 'required' : 'nullable', 'string', 'regex:/^[a-z0-9-]{8,40}$/'],
        ]);

        // Dos filas con la misma ref romperían la marca única de cada pago.
        if (count(array_unique(array_column($d['filas'], 'ref'))) !== count($d['filas'])) {
            abort(422, 'Hay filas repetidas en la lista.');
        }

        return $d;
    }

    /** @return array{filas: list<array<string,mixed>>, resumen: array<string,mixed>} */
    private function correr(array $d, bool $aplicar, string $estadoOk): array
    {
        // Una fecha pasada se guarda al mediodía (sólo interesa el día). Si es HOY se deja la
        // hora real: antes todos los pagos de un lote quedaban con las 12:00:00 en el registro.
        $fecha = (!empty($d['fecha']) && $d['fecha'] !== now()->toDateString()) ? Carbon::parse($d['fecha'])->setTime(12, 0) : null;

        $motor = new AplicarPagoALasFacturas(
            $this->empresa(), $aplicar ? (int) getSessionUserId() : null, $d['metodo_id'] ?? null, $fecha, $aplicar, $d['orden'] === 'exacta',
        );

        $filas = [];

        foreach ($d['filas'] as $f) {
            $marca = 'Lote ' . ($d['lote'] ?? 'simulacion') . ' · ' . $f['ref'] . ' · conciliación web';

            try {
                $r = $motor->pagar((string) $f['cedula'], (float) $f['valor'], $marca, (bool) ($f['forzar'] ?? false));
            } catch (\Throwable $e) {
                Log::warning('[Conciliación] Falló un pago', ['ref' => $f['ref'], 'error' => $e->getMessage()]);
                $r = ['estado' => 'error', 'user_id' => null, 'cliente' => null, 'movimientos' => [], 'sobrante' => 0.0, 'detalle' => $e->getMessage(), 'quedan' => 0];
            }

            $filas[] = ['ref' => $f['ref'], 'cedula' => $f['cedula'], 'nombre' => $f['nombre'] ?? '', 'valor' => (float) $f['valor']] + $r;
        }

        $ok = collect($filas)->whereIn('estado', [$aplicar ? 'aplicado' : 'simulado']);

        return [
            'filas'   => $filas,
            'resumen' => [
                'pagos'           => count($filas),
                'pagos_aplicados' => $ok->count(),
                'aplicado'        => round($ok->sum(fn ($x) => collect($x['movimientos'])->sum('monto')), 2),
                'sin_aplicar'     => round(collect($filas)->sum(fn ($x) => in_array($x['estado'], ['aplicado', 'simulado'], true) ? $x['sobrante'] : (float) $x['valor']), 2),
                'por_estado'      => collect($filas)->groupBy('estado')->map(fn ($g) => ['pagos' => $g->count(), 'valor' => round($g->sum('valor'), 2)])->all(),
                'sin_pendientes'  => $ok->where('quedan', 0)->count(),
            ],
        ];
    }
}
