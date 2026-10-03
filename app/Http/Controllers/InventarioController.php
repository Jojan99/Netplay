<?php

namespace App\Http\Controllers;

use App\Services\Inventario\AnalisisDeInventario;
use App\Services\Inventario\AsistenteDeInventario;
use App\Services\Inventario\Bodega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La parte del inventario que sabe dónde está cada equipo: lector de códigos, entrega y
 * devolución a técnicos, el tablero con lo que se acaba y el asistente.
 *
 * El CRUD de ítems, categorías y el historial de movimientos sigue en InventoryController.
 */
class InventarioController extends Controller
{
    private function empresa(): int
    {
        return (int) getSessionCompanyId();
    }

    private function usuario(): ?int
    {
        return getSessionUserId() ? (int) getSessionUserId() : null;
    }

    private function bien(string $mensaje, mixed $data = null): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $data, 'error' => 0]);
    }

    private function mal(string $mensaje, int $http = 200): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => null, 'error' => 1], $http);
    }

    /** Ejecuta una operación de Bodega y traduce sus errores de negocio en un mensaje. */
    private function operar(string $mensajeOk, callable $fn): JsonResponse
    {
        try {
            return $this->bien($mensajeOk, $fn(new Bodega($this->empresa())));
        } catch (\DomainException $e) {
            return $this->mal($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[Inventario] Falló la operación', ['error' => $e->getMessage()]);

            return $this->mal('No se pudo registrar. Intente de nuevo.');
        }
    }

    // ── Tablero ──────────────────────────────────────────────────────────────

    /** GET api/inventory/panel — cifras, estado de cada ítem, técnicos y recomendaciones. */
    public function panel(): JsonResponse
    {
        $a = new AnalisisDeInventario($this->empresa());
        $items = $a->items();
        $tecnicos = $a->tecnicos();

        return $this->bien('OK', [
            'resumen' => $a->resumen($items, $tecnicos),
            'items' => $items,
            'tecnicos' => $tecnicos,
            'recomendaciones' => $a->recomendaciones($items, $tecnicos),
            'con_ia' => \App\Services\Cobranza\Ia::disponible($this->empresa()),
            'dias_con_tecnico' => AnalisisDeInventario::DIAS_CON_TECNICO,
        ]);
    }

    /** POST api/inventory/asistente {pregunta, historial:[{rol,texto}]} */
    public function asistente(Request $r): JsonResponse
    {
        $d = $r->validate(['pregunta' => 'required|string|max:1500', 'historial' => 'nullable|array|max:30', 'historial.*.rol' => 'string', 'historial.*.texto' => 'string|max:4000']);

        return $this->bien('OK', (new AsistenteDeInventario($this->empresa()))->preguntar($d['pregunta'], $d['historial'] ?? []));
    }

    // ── Lector de códigos ────────────────────────────────────────────────────

    /** GET api/inventory/buscar?codigo= — qué es lo que se acaba de leer. */
    public function buscar(Request $r): JsonResponse
    {
        $codigo = trim((string) $r->query('codigo'));

        if ($codigo === '') {
            return $this->mal('Falta el código.');
        }

        return $this->bien('OK', (new Bodega($this->empresa()))->buscar($codigo));
    }

    /** GET api/inventory/tecnicos — a quién se le puede entregar. */
    public function tecnicos(): JsonResponse
    {
        $empresa = $this->empresa();

        return $this->bien('OK', DB::table('users as u')->join('user_data as d', 'd.user_id', '=', 'u.id')->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('u.company_id', $empresa)->where('d.active', 1)->whereIn(DB::raw('UPPER(p.name)'), ['TECNICO', 'ADMIN'])
            ->orderByRaw("UPPER(p.name) = 'TECNICO' DESC")->orderBy('d.names')
            ->get(['u.id', DB::raw("TRIM(CONCAT(COALESCE(d.names,''), ' ', COALESCE(d.lastname,''))) as nombre"), 'p.name as perfil']));
    }

    /** GET api/inventory/unidades?inventory_id=&estado=&tecnico_id=&q=&pagina= — los equipos con serial. */
    public function unidades(Request $r): JsonResponse
    {
        $empresa = $this->empresa();
        $t = strtoupper(trim((string) $r->query('q')));

        $q = DB::table('inventory_units as n')->join('inventories as i', 'i.id', '=', 'n.inventory_id')
            ->leftJoin('user_data as t', 't.user_id', '=', 'n.tecnico_id')
            ->leftJoin('user_data as c', fn ($j) => $j->on('c.user_id', '=', 'n.cliente_user_id')->where('c.company_id', $empresa))
            ->where('n.company_id', $empresa)
            ->when($r->query('inventory_id'), fn ($x, $id) => $x->where('n.inventory_id', (int) $id))
            ->when($r->query('estado'), fn ($x, $e) => $x->where('n.estado', $e))
            ->when($r->query('tecnico_id'), fn ($x, $id) => $x->where('n.tecnico_id', (int) $id))
            ->when($t !== '', fn ($x) => $x->where(fn ($y) => $y->where('n.serial', 'like', "%{$t}%")->orWhere('n.serial_canonico', 'like', '%' . \App\Support\Serial::canonico($t) . '%')));

        $total = (clone $q)->count();
        $filas = $q->orderByDesc('n.updated_at')->forPage(max(1, (int) $r->query('pagina', 1)), 30)->get([
            'n.id', 'n.inventory_id', 'i.name as item', 'n.serial', 'n.mac', 'n.estado', 'n.tecnico_id', 'n.entregada_en', 'n.instalada_en', 'n.installation_order_id', 'n.cliente_user_id', 'n.nota', 'n.created_at',
            DB::raw("TRIM(CONCAT(COALESCE(t.names,''), ' ', COALESCE(t.lastname,''))) as tecnico"),
            DB::raw("TRIM(CONCAT(COALESCE(c.names,''), ' ', COALESCE(c.lastname,''))) as cliente"),
        ]);

        return $this->bien('OK', ['total' => $total, 'por_pagina' => 30, 'unidades' => $filas,
            'por_estado' => DB::table('inventory_units')->where('company_id', $empresa)->when($r->query('inventory_id'), fn ($x, $id) => $x->where('inventory_id', (int) $id))->selectRaw('estado, COUNT(*) n')->groupBy('estado')->pluck('n', 'estado')]);
    }

    // ── Movimientos ──────────────────────────────────────────────────────────

    private function reglas(array $extra = []): array
    {
        return $extra + [
            'inventory_id' => 'required|integer',
            'cantidad'     => 'nullable|numeric|min:0|max:100000',
            'seriales'     => 'nullable|array|max:500',
            'seriales.*'   => 'string|max:60',
            'nota'         => 'nullable|string|max:200',
        ];
    }

    /** POST api/inventory/entradas — llega mercancía; con seriales, un equipo por cada uno. */
    public function entrada(Request $r): JsonResponse
    {
        $d = $r->validate($this->reglas(['precio' => 'nullable|numeric|min:0', 'referencia' => 'nullable|string|max:120']));

        return $this->operar('Entrada registrada.', fn (Bodega $b) => $b->entrada((int) $d['inventory_id'], (float) ($d['cantidad'] ?? 0), (float) ($d['precio'] ?? 0), $d['seriales'] ?? [], $d['referencia'] ?? null, $d['nota'] ?? null, $this->usuario()));
    }

    /** POST api/inventory/salidas — sale y se gasta (venta, pérdida, baja). */
    public function salida(Request $r): JsonResponse
    {
        $d = $r->validate($this->reglas(['referencia' => 'nullable|string|max:120', 'danado' => 'nullable|boolean']));

        return $this->operar('Salida registrada.', fn (Bodega $b) => $b->salida((int) $d['inventory_id'], (float) ($d['cantidad'] ?? 0), $d['referencia'] ?? null, $d['nota'] ?? null, $this->usuario(), $d['seriales'] ?? [], !empty($d['danado']) ? 'danada' : 'baja'));
    }

    /** POST api/inventory/ajustes {inventory_id, contado, nota} — conteo físico. */
    public function ajuste(Request $r): JsonResponse
    {
        $d = $r->validate(['inventory_id' => 'required|integer', 'contado' => 'required|numeric|min:0|max:1000000', 'nota' => 'nullable|string|max:200']);

        return $this->operar('Existencia ajustada al conteo.', fn (Bodega $b) => $b->ajuste((int) $d['inventory_id'], (float) $d['contado'], $d['nota'] ?? null, $this->usuario()));
    }

    /** POST api/inventory/entregas — material que se lleva un técnico. */
    public function entregar(Request $r): JsonResponse
    {
        $d = $r->validate($this->reglas(['tecnico_id' => 'required|integer']));

        return $this->operar('Entregado al técnico.', fn (Bodega $b) => $b->entregar((int) $d['tecnico_id'], (int) $d['inventory_id'], (float) ($d['cantidad'] ?? 0), $d['seriales'] ?? [], $d['nota'] ?? null, $this->usuario()));
    }

    /** POST api/inventory/devoluciones — el técnico devuelve (o entrega dañado). */
    public function devolver(Request $r): JsonResponse
    {
        $d = $r->validate($this->reglas(['tecnico_id' => 'required|integer', 'danado' => 'nullable|boolean']));

        return $this->operar('Devolución registrada.', fn (Bodega $b) => $b->devolver((int) $d['tecnico_id'], (int) $d['inventory_id'], (float) ($d['cantidad'] ?? 0), $d['seriales'] ?? [], !empty($d['danado']), $d['nota'] ?? null, $this->usuario()));
    }

    /** POST api/inventory/consumos — el técnico gastó material sin serial de lo que tenía. */
    public function consumir(Request $r): JsonResponse
    {
        $d = $r->validate(['tecnico_id' => 'required|integer', 'inventory_id' => 'required|integer', 'cantidad' => 'required|numeric|min:0.01|max:100000', 'nota' => 'nullable|string|max:200', 'referencia' => 'nullable|string|max:120']);

        return $this->operar('Consumo registrado.', fn (Bodega $b) => $b->consumir((int) $d['tecnico_id'], (int) $d['inventory_id'], (float) $d['cantidad'], $d['referencia'] ?? null, $d['nota'] ?? null, $this->usuario()));
    }

    // ── Para el técnico ──────────────────────────────────────────────────────

    /** GET api/inventory/mi-material — lo que tiene a su nombre quien está en sesión. */
    public function miMaterial(): JsonResponse
    {
        $yo = $this->usuario();
        $mio = collect((new AnalisisDeInventario($this->empresa()))->tecnicos())->firstWhere('tecnico_id', $yo);

        return $this->bien('OK', $mio ?: ['tecnico_id' => $yo, 'equipos' => [], 'material' => [], 'equipos_total' => 0, 'equipos_viejos' => 0, 'dias_max' => 0, 'valor' => 0]);
    }
}
