<?php

namespace App\Services\Inventario;

use Illuminate\Support\Facades\DB;

/**
 * Lo que dicen los números del inventario, sin IA: qué se acaba, para cuántos días alcanza,
 * qué tiene cada técnico y desde hace cuánto, y qué conviene hacer.
 *
 * El asistente parte de aquí. Si no hay IA conectada, esto ya es una lista de recomendaciones
 * que se puede leer tal cual.
 */
class AnalisisDeInventario
{
    /** Con más de estos días en manos del técnico, un equipo merece una pregunta. */
    public const DIAS_CON_TECNICO = 15;
    /** Sin entrar ni salir en este tiempo, el ítem está quieto. */
    public const DIAS_QUIETO = 90;
    /** El consumo se mide sobre esta ventana. */
    public const VENTANA = 30;

    public function __construct(private int $companyId) {}

    /**
     * Cada ítem con su existencia, lo que tienen los técnicos, su consumo y para cuánto alcanza.
     *
     * @return list<array<string,mixed>>
     */
    public function items(): array
    {
        $desde = now()->subDays(self::VENTANA);

        // Lo que salió de la bodega: salidas y entregas a técnicos; lo devuelto se resta. Lo que
        // un técnico gasta de lo que ya tenía («consumo») no vuelve a salir de la bodega.
        $consumo = DB::table('inventory_movements')->where('company_id', $this->companyId)->whereNull('deleted_at')->where('created_at', '>=', $desde)
            ->selectRaw("inventory_id,
                SUM(CASE WHEN type IN ('salida','entrega') THEN quantity ELSE 0 END) sale,
                SUM(CASE WHEN type = 'devolucion' THEN quantity ELSE 0 END) vuelve,
                SUM(CASE WHEN type IN ('salida','consumo') AND reference LIKE 'instalacion:%' AND serial_number IS NOT NULL THEN quantity ELSE 0 END) instaladas")
            ->groupBy('inventory_id')->get()->keyBy('inventory_id');

        $ultimo = DB::table('inventory_movements')->where('company_id', $this->companyId)->whereNull('deleted_at')
            ->selectRaw('inventory_id, MAX(created_at) ultimo')->groupBy('inventory_id')->pluck('ultimo', 'inventory_id');

        $conTecnicos = DB::table('inventory_units')->where('company_id', $this->companyId)->where('estado', 'tecnico')
            ->selectRaw('inventory_id, COUNT(*) n')->groupBy('inventory_id')->pluck('n', 'inventory_id');
        $custodias = DB::table('inventory_custodias')->where('company_id', $this->companyId)->where('cantidad', '>', 0)
            ->selectRaw('inventory_id, SUM(cantidad) n')->groupBy('inventory_id')->pluck('n', 'inventory_id');
        $danadas = DB::table('inventory_units')->where('company_id', $this->companyId)->where('estado', 'danada')
            ->selectRaw('inventory_id, COUNT(*) n')->groupBy('inventory_id')->pluck('n', 'inventory_id');

        $filas = [];

        foreach (DB::table('inventories as i')->leftJoin('inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->where('i.company_id', $this->companyId)->whereNull('i.deleted_at')->orderBy('i.name')
            ->get(['i.id', 'i.name', 'i.sku', 'i.barcode', 'i.quantity', 'i.stock_min', 'i.stock_max', 'i.unit', 'i.unit_price', 'i.average_cost', 'i.usa_serial', 'i.location', 'i.created_at', 'c.name as categoria']) as $i) {
            $m = $consumo[$i->id] ?? null;
            // Las instalaciones hechas con equipo que ya tenía el técnico no salen otra vez de bodega.
            $gastado = max(0, (float) ($m->sale ?? 0) - (float) ($m->vuelve ?? 0));
            $porDia  = $gastado / self::VENTANA;
            $bodega  = (float) $i->quantity;
            $enCalle = (float) ($conTecnicos[$i->id] ?? 0) + (float) ($custodias[$i->id] ?? 0);
            $minimo  = (float) $i->stock_min;
            $dias    = $porDia > 0 ? (int) floor($bodega / $porDia) : null;
            $quieto  = isset($ultimo[$i->id]) ? (int) now()->diffInDays($ultimo[$i->id]) : (int) now()->diffInDays($i->created_at);

            $estado = match (true) {
                $bodega <= 0 && ($minimo > 0 || $gastado > 0)       => 'agotado',
                $minimo > 0 && $bodega <= $minimo                   => 'bajo',
                $dias !== null && $dias <= 7                         => 'por_agotarse',
                $i->stock_max !== null && $bodega > (float) $i->stock_max => 'sobra',
                $bodega > 0 && $quieto >= self::DIAS_QUIETO          => 'quieto',
                default                                              => 'bien',
            };

            // Para llegar a un mes de cobertura (o al máximo que fijó la empresa).
            $objetivo = $i->stock_max !== null ? (float) $i->stock_max : max($minimo * 2, ceil($porDia * 30));
            $pedir = in_array($estado, ['agotado', 'bajo', 'por_agotarse'], true) ? max(0, (int) ceil($objetivo - $bodega)) : 0;

            $filas[] = [
                'id' => (int) $i->id, 'nombre' => $i->name, 'categoria' => $i->categoria, 'unidad' => $i->unit, 'usa_serial' => (bool) $i->usa_serial,
                'en_bodega' => $bodega, 'con_tecnicos' => $enCalle, 'danadas' => (int) ($danadas[$i->id] ?? 0),
                'minimo' => $minimo, 'maximo' => $i->stock_max !== null ? (float) $i->stock_max : null,
                'consumo_30d' => round($gastado, 2), 'instaladas_30d' => (float) ($m->instaladas ?? 0),
                'dias_de_cobertura' => $dias, 'dias_sin_movimiento' => $quieto,
                'valor_en_bodega' => round($bodega * (float) ($i->average_cost ?: $i->unit_price), 2),
                'estado' => $estado, 'sugerido_pedir' => $pedir,
            ];
        }

        return $filas;
    }

    /**
     * Lo que tiene cada técnico: equipos con serial y material suelto, con los días que lleva.
     *
     * @return list<array<string,mixed>>
     */
    public function tecnicos(): array
    {
        $porTecnico = [];
        $nombre = fn ($t) => trim(($t->names ?? '') . ' ' . ($t->lastname ?? '')) ?: 'Técnico ' . $t->tecnico_id;

        foreach (DB::table('inventory_units as n')->join('inventories as i', 'i.id', '=', 'n.inventory_id')->leftJoin('user_data as t', 't.user_id', '=', 'n.tecnico_id')
            ->where('n.company_id', $this->companyId)->where('n.estado', 'tecnico')->orderBy('n.entregada_en')
            ->get(['n.id', 'n.tecnico_id', 'n.serial', 'n.entregada_en', 'n.inventory_id', 'i.name', 'i.average_cost', 'i.unit_price', 't.names', 't.lastname']) as $u) {
            $dias = $u->entregada_en ? (int) now()->diffInDays($u->entregada_en) : null;
            $porTecnico[$u->tecnico_id] ??= ['tecnico_id' => (int) $u->tecnico_id, 'nombre' => $nombre($u), 'equipos' => [], 'material' => []];
            $porTecnico[$u->tecnico_id]['equipos'][] = [
                'unidad_id' => (int) $u->id, 'inventory_id' => (int) $u->inventory_id, 'item' => $u->name, 'serial' => $u->serial,
                'entregada_en' => $u->entregada_en, 'dias' => $dias, 'valor' => (float) ($u->average_cost ?: $u->unit_price),
            ];
        }

        foreach (DB::table('inventory_custodias as k')->join('inventories as i', 'i.id', '=', 'k.inventory_id')->leftJoin('user_data as t', 't.user_id', '=', 'k.tecnico_id')
            ->where('k.company_id', $this->companyId)->where('k.cantidad', '>', 0)
            ->get(['k.tecnico_id', 'k.cantidad', 'k.desde', 'k.inventory_id', 'i.name', 'i.unit', 'i.average_cost', 'i.unit_price', 't.names', 't.lastname']) as $k) {
            $porTecnico[$k->tecnico_id] ??= ['tecnico_id' => (int) $k->tecnico_id, 'nombre' => $nombre($k), 'equipos' => [], 'material' => []];
            $porTecnico[$k->tecnico_id]['material'][] = [
                'inventory_id' => (int) $k->inventory_id, 'item' => $k->name, 'cantidad' => (float) $k->cantidad, 'unidad' => $k->unit,
                'desde' => $k->desde, 'dias' => $k->desde ? (int) now()->diffInDays($k->desde) : null,
                'valor' => round((float) $k->cantidad * (float) ($k->average_cost ?: $k->unit_price), 2),
            ];
        }

        // Cuánto instala cada uno: para comparar lo que tiene con lo que gasta.
        $instaladas = DB::table('inventory_movements')->where('company_id', $this->companyId)->whereNull('deleted_at')->whereIn('type', ['salida', 'consumo'])
            // Sólo equipos (con serial): los metros de cable gastados no son instalaciones.
            ->where('reference', 'like', 'instalacion:%')->whereNotNull('serial_number')->where('created_at', '>=', now()->subDays(self::VENTANA))->whereNotNull('tecnico_id')
            ->selectRaw('tecnico_id, SUM(quantity) n')->groupBy('tecnico_id')->pluck('n', 'tecnico_id');

        $lista = [];

        foreach ($porTecnico as $id => $t) {
            $diasEquipos = array_filter(array_column($t['equipos'], 'dias'), fn ($d) => $d !== null);
            $viejos = count(array_filter($t['equipos'], fn ($e) => ($e['dias'] ?? 0) > self::DIAS_CON_TECNICO));
            $t['equipos_total'] = count($t['equipos']);
            $t['equipos_viejos'] = $viejos;
            $t['dias_max'] = max(array_merge([0], $diasEquipos, array_filter(array_column($t['material'], 'dias'), fn ($d) => $d !== null)));
            $t['dias_promedio'] = $diasEquipos ? (int) round(array_sum($diasEquipos) / count($diasEquipos)) : null;
            $t['valor'] = round(array_sum(array_column($t['equipos'], 'valor')) + array_sum(array_column($t['material'], 'valor')), 2);
            $t['instaladas_30d'] = (float) ($instaladas[$id] ?? 0);
            $lista[] = $t;
        }

        usort($lista, fn ($a, $b) => $b['dias_max'] <=> $a['dias_max']);

        return $lista;
    }

    /**
     * Qué conviene hacer, en frases. De lo más urgente a lo menos.
     *
     * @return list<array{nivel:string, titulo:string, detalle:string, item_id?:int, tecnico_id?:int}>
     */
    public function recomendaciones(?array $items = null, ?array $tecnicos = null): array
    {
        $items ??= $this->items();
        $tecnicos ??= $this->tecnicos();
        $r = [];
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

        foreach ($items as $i) {
            $alcanza = $i['dias_de_cobertura'] !== null ? " Al ritmo del último mes ({$n($i['consumo_30d'])} en 30 días) alcanza para {$i['dias_de_cobertura']} día(s)." : '';
            $pedir = $i['sugerido_pedir'] > 0 ? " Sugerido: pedir {$i['sugerido_pedir']} {$i['unidad']}." : '';
            $calle = $i['con_tecnicos'] > 0 ? " Los técnicos tienen {$n($i['con_tecnicos'])} sin instalar: antes de comprar, mire si pueden devolver." : '';

            match ($i['estado']) {
                'agotado' => $r[] = ['nivel' => 'critico', 'titulo' => "Se acabó «{$i['nombre']}»", 'detalle' => 'No queda ninguno en bodega.' . $calle . $pedir, 'item_id' => $i['id']],
                'bajo' => $r[] = ['nivel' => 'alto', 'titulo' => "«{$i['nombre']}» está en el mínimo", 'detalle' => "Quedan {$n($i['en_bodega'])} y el mínimo es {$n($i['minimo'])}." . $alcanza . $calle . $pedir, 'item_id' => $i['id']],
                'por_agotarse' => $r[] = ['nivel' => 'alto', 'titulo' => "«{$i['nombre']}» se acaba esta semana", 'detalle' => "Quedan {$n($i['en_bodega'])}." . $alcanza . $calle . $pedir, 'item_id' => $i['id']],
                'sobra' => $r[] = ['nivel' => 'info', 'titulo' => "Hay de más de «{$i['nombre']}»", 'detalle' => "Hay {$n($i['en_bodega'])} y el máximo es {$n($i['maximo'])}: plata quieta en la bodega.", 'item_id' => $i['id']],
                'quieto' => $r[] = ['nivel' => 'info', 'titulo' => "«{$i['nombre']}» no se mueve", 'detalle' => "Lleva {$i['dias_sin_movimiento']} días sin entrar ni salir, con {$n($i['en_bodega'])} en bodega.", 'item_id' => $i['id']],
                default => null,
            };

            if ($i['minimo'] <= 0 && $i['consumo_30d'] > 0) {
                $r[] = ['nivel' => 'info', 'titulo' => "«{$i['nombre']}» no tiene mínimo", 'detalle' => 'Se gasta (' . $n($i['consumo_30d']) . ' en 30 días) pero no tiene mínimo: sin él no se puede avisar a tiempo. Un buen punto de partida es lo que se gasta en dos semanas: ' . max(1, (int) ceil($i['consumo_30d'] / 2)) . '.', 'item_id' => $i['id']];
            }
            if ($i['danadas'] > 0) {
                $r[] = ['nivel' => 'info', 'titulo' => "{$i['danadas']} equipo(s) dañado(s) de «{$i['nombre']}»", 'detalle' => 'Están registrados como dañados: tramite la garantía o délos de baja.', 'item_id' => $i['id']];
            }
        }

        foreach ($tecnicos as $t) {
            if ($t['equipos_viejos'] > 0) {
                $r[] = ['nivel' => 'alto', 'titulo' => "{$t['nombre']} tiene {$t['equipos_viejos']} equipo(s) hace más de " . self::DIAS_CON_TECNICO . ' días',
                    'detalle' => "El más viejo lleva {$t['dias_max']} días con él. En el último mes instaló {$n($t['instaladas_30d'])}. Pídale que los devuelva o que diga dónde están.", 'tecnico_id' => $t['tecnico_id']];
            } elseif ($t['equipos_total'] > 0 && $t['instaladas_30d'] > 0 && $t['equipos_total'] > $t['instaladas_30d'] * 2) {
                $r[] = ['nivel' => 'info', 'titulo' => "{$t['nombre']} carga más de lo que instala",
                    'detalle' => "Tiene {$t['equipos_total']} equipos y en el último mes instaló {$n($t['instaladas_30d'])}: puede devolver una parte a la bodega.", 'tecnico_id' => $t['tecnico_id']];
            }
        }

        $orden = ['critico' => 0, 'alto' => 1, 'info' => 2];
        usort($r, fn ($a, $b) => $orden[$a['nivel']] <=> $orden[$b['nivel']]);

        return $r;
    }

    /** Las cifras de arriba de la pantalla. */
    public function resumen(?array $items = null, ?array $tecnicos = null): array
    {
        $items ??= $this->items();
        $tecnicos ??= $this->tecnicos();

        return [
            'items' => count($items),
            'valor_en_bodega' => round(array_sum(array_column($items, 'valor_en_bodega')), 2),
            'valor_con_tecnicos' => round(array_sum(array_column($tecnicos, 'valor')), 2),
            'agotados' => count(array_filter($items, fn ($i) => $i['estado'] === 'agotado')),
            'bajos' => count(array_filter($items, fn ($i) => in_array($i['estado'], ['bajo', 'por_agotarse'], true))),
            'tecnicos_con_material' => count($tecnicos),
            'equipos_con_tecnicos' => array_sum(array_column($tecnicos, 'equipos_total')),
            'equipos_viejos' => array_sum(array_column($tecnicos, 'equipos_viejos')),
        ];
    }
}
