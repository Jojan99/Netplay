<?php

namespace App\Services\Facturacion;

use App\Models\PaymentLog;
use Illuminate\Support\Facades\DB;

/**
 * Un lote es una tanda de pagos que se aplicó de una sola vez (una lista pegada en la pantalla,
 * o un archivo por consola). Aquí se abre y se cierra su registro, y se DESHACE.
 *
 * Deshacer no restaura el estado «de antes» de las facturas: eso borraría cualquier pago que
 * llegó DESPUÉS a esas mismas facturas. Se resta, factura por factura, exactamente lo que aplicó
 * este lote. El estado de antes (`antes`) sólo sirve para dejar los campos accesorios como estaban
 * cuando el resultado coincide, y como constancia.
 *
 * Sigue la convención del panel para revertir un pago (FacturationRepository::revertirPago): un
 * movimiento NEGATIVO de tipo `reverso` —así la suma de los movimientos sigue dando lo realmente
 * cobrado— y la factura vuelve a pendiente con su abono reducido. Los movimientos originales no
 * se borran: el rastro queda completo.
 *
 * Es de todo o nada. Si una sola factura no se puede deshacer con seguridad —el abono actual es
 * menor que lo que aplicó el lote, señal de que se tocó por otro lado— no se toca ninguna.
 */
class LoteDePagos
{
    /** Registra que se va a aplicar un lote, con cómo estaban las facturas que va a tocar. */
    public static function abrir(int $companyId, string $lote, ?int $userId, ?int $metodoId, string $orden, ?string $fecha, ?string $titulo, array $detIds, string $origen = 'web'): void
    {
        if (DB::table('conciliacion_pagos_lotes')->where('company_id', $companyId)->where('lote', $lote)->exists()) return;

        // Sólo cuando el lote es nuevo: si se aplica dos veces, el segundo «antes» ya tendría los pagos
        // puestos y no serviría de constancia.
        DB::table('conciliacion_pagos_lotes')->insert([
            'company_id' => $companyId, 'lote' => $lote, 'origen' => $origen, 'user_id' => $userId, 'payment_method_id' => $metodoId,
            'titulo' => $titulo, 'orden' => $orden, 'fecha_pago' => $fecha,
            'antes' => json_encode(DB::table('det_facturations')->whereIn('id', $detIds)->get(), JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function cerrar(int $companyId, string $lote, array $resumen): void
    {
        DB::table('conciliacion_pagos_lotes')->where('company_id', $companyId)->where('lote', $lote)->update([
            'pagos' => $resumen['pagos_aplicados'], 'aplicado' => $resumen['aplicado'], 'sin_aplicar' => $resumen['sin_aplicar'],
            'resumen' => json_encode($resumen), 'updated_at' => now(),
        ]);
    }

    /** Lo que este lote aplicó, por factura. */
    private static function movimientos(int $companyId, string $lote)
    {
        return PaymentLog::where('company_id', $companyId)
            ->where('notes', 'like', "Lote {$lote} · %")
            ->whereIn('type', ['pago_completo', 'abono'])->where('amount', '>', 0)
            ->orderBy('id')->get();
    }

    /**
     * Qué pasaría si se deshace, sin tocar nada.
     *
     * @return array<string,mixed>
     */
    public static function vistaPrevia(int $companyId, string $lote): array
    {
        $fila = DB::table('conciliacion_pagos_lotes')->where('company_id', $companyId)->where('lote', $lote)->first();
        if (!$fila) return ['existe' => false, 'puede' => false, 'motivo' => 'Ese lote no existe.', 'items' => [], 'conflictos' => []];

        $antes = collect(json_decode((string) $fila->antes, true) ?: [])->keyBy('id');
        $porFactura = self::movimientos($companyId, $lote)->groupBy('det_facturation_id');

        $items = []; $conflictos = [];

        foreach ($porFactura as $detId => $g) {
            $det = DB::table('det_facturations')->where('id', $detId)->first();
            if (!$det) { $conflictos[] = "La factura {$g[0]->number_facture} ya no existe."; continue; }

            $r = self::calcular($det, round((float) $g->sum('amount'), 2), $antes->get($detId));

            // ¿Hubo movimientos de ESTA factura después del lote, de otro origen?
            $despues = PaymentLog::where('det_facturation_id', $detId)->where('id', '>', (int) $g->max('id'))
                ->whereNotIn('id', $g->pluck('id'))->whereIn('type', ['pago_completo', 'abono', 'reverso'])->count();

            if ($r['conflicto']) $conflictos[] = "{$det->number_facture}: {$r['conflicto']}";

            $items[] = [
                'det_id' => (int) $detId, 'factura' => $det->number_facture, 'cliente' => $g[0]->client_name,
                'monto_del_lote' => $r['monto'], 'total' => $r['total'], 'abono_actual' => round((float) ($det->price_abone ?? 0), 2),
                'abono_quedaria' => $r['nuevo'], 'pagada_ahora' => (bool) $det->paid, 'pagada_quedaria' => $r['pagada'],
                'movimientos_posteriores' => $despues, 'conflicto' => $r['conflicto'],
            ];
        }

        $ya = !empty($fila->revertido_en);

        return [
            'existe' => true, 'ya_revertido' => $ya, 'revertido_en' => $fila->revertido_en,
            'lote' => ['lote' => $fila->lote, 'titulo' => $fila->titulo, 'origen' => $fila->origen, 'pagos' => (int) $fila->pagos, 'aplicado' => (float) $fila->aplicado, 'creado' => $fila->created_at],
            'puede' => !$ya && $items && !$conflictos,
            'motivo' => $ya ? 'Este lote ya se deshizo.' : (!$items ? 'Este lote no tiene movimientos.' : ($conflictos ? 'Hay facturas que no se pueden deshacer con seguridad.' : null)),
            'items' => $items, 'conflictos' => $conflictos,
            'resumen' => [
                'facturas' => count($items), 'clientes' => count(array_unique(array_column($items, 'cliente'))),
                'monto' => round(array_sum(array_column($items, 'monto_del_lote')), 2),
                'con_pagos_posteriores' => count(array_filter($items, fn ($i) => $i['movimientos_posteriores'] > 0)),
                'quedan_pendientes' => count(array_filter($items, fn ($i) => !$i['pagada_quedaria'])),
            ],
        ];
    }

    /** @return array{monto:float,total:float,nuevo:float,pagada:bool,conflicto:?string} */
    private static function calcular(object $det, float $monto, ?array $antes): array
    {
        $total = round((float) $det->price_total - (float) ($det->price_discount ?? 0), 2);
        $nuevo = round((float) ($det->price_abone ?? 0) - $monto, 2);
        $conflicto = null;

        if ($nuevo < -0.01) {
            $conflicto = 'el abono actual ($' . number_format((float) $det->price_abone, 0, ',', '.') . ') es menor que lo que aplicó este lote ($' . number_format($monto, 0, ',', '.') . '): se tocó por otro lado.';
        }

        $nuevo = max(0.0, $nuevo);

        // Si otro pago dejó la factura cubierta, sigue pagada; si no, vuelve a pendiente.
        return ['monto' => $monto, 'total' => $total, 'nuevo' => $nuevo, 'pagada' => $total > 0 && $nuevo >= $total - 0.004, 'conflicto' => $conflicto];
    }

    /**
     * Deshace el lote. Todo o nada.
     *
     * @return array{ok:bool, message:string, facturas:int, monto:float}
     */
    public static function revertir(int $companyId, string $lote, ?int $userId): array
    {
        $prev = self::vistaPrevia($companyId, $lote);
        if (!$prev['puede']) return ['ok' => false, 'message' => $prev['motivo'] ?? 'No se puede deshacer.', 'facturas' => 0, 'monto' => 0.0];

        $fila = DB::table('conciliacion_pagos_lotes')->where('company_id', $companyId)->where('lote', $lote)->first();
        $antes = collect(json_decode((string) $fila->antes, true) ?: [])->keyBy('id');
        $porFactura = self::movimientos($companyId, $lote)->groupBy('det_facturation_id');

        DB::transaction(function () use ($porFactura, $antes, $companyId, $lote, $userId, $fila) {
            // Las facturas se vuelven a leer con candado: entre la vista previa y este momento alguien pudo tocarlas.
            foreach ($porFactura as $detId => $g) {
                $det = DB::table('det_facturations')->where('id', $detId)->lockForUpdate()->first();
                $r = self::calcular($det, round((float) $g->sum('amount'), 2), $antes->get($detId));

                if ($r['conflicto']) throw new \RuntimeException("{$det->number_facture}: {$r['conflicto']}");

                $a = $antes->get($detId);
                // Si el resultado es exactamente el de antes, se dejan los campos accesorios como estaban.
                $igual = $a && abs((float) ($a['price_abone'] ?? 0) - $r['nuevo']) < 0.01;

                DB::table('det_facturations')->where('id', $detId)->update([
                    'price_abone'     => $r['nuevo'],
                    'abone'           => $igual ? (int) ($a['abone'] ?? 0) : ($r['nuevo'] > 0 ? 1 : 0),
                    'paid'            => $r['pagada'] ? 1 : 0,
                    'paid_at'         => $r['pagada'] ? ($igual ? ($a['paid_at'] ?? $det->paid_at) : $det->paid_at) : null,
                    'paid_by_user_id' => $r['pagada'] ? ($igual ? ($a['paid_by_user_id'] ?? $det->paid_by_user_id) : $det->paid_by_user_id) : null,
                    'updated_at'      => now(),
                ]);

                PaymentLog::create([
                    'company_id' => $companyId, 'det_facturation_id' => $detId, 'cab_id' => $det->cab_id, 'number_facture' => $det->number_facture,
                    'client_name' => $g[0]->client_name, 'recorded_by_user_id' => $userId,
                    // Negativo: la suma de los movimientos sigue dando lo cobrado de verdad.
                    'amount' => -$r['monto'], 'type' => 'reverso', 'payment_method_id' => $fila->payment_method_id,
                    'notes' => mb_substr("Reverso del lote {$lote}", 0, 255),
                ]);
            }

            DB::table('conciliacion_pagos_lotes')->where('id', $fila->id)->update(['revertido_en' => now(), 'revertido_por' => $userId, 'updated_at' => now()]);
        });

        return ['ok' => true, 'message' => 'Lote deshecho: las facturas volvieron a como estaban.', 'facturas' => $prev['resumen']['facturas'], 'monto' => $prev['resumen']['monto']];
    }
}
