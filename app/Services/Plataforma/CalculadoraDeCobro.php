<?php

namespace App\Services\Plataforma;

use Illuminate\Support\Facades\DB;

/**
 * Cuánto le toca pagar a una empresa este período, y de dónde sale cada peso.
 *
 * El orden es siempre el mismo y está a la vista en el detalle:
 *
 *   1. precio pactado  (el de la suscripción; si no hay, el de lista del plan)
 *   2. cupón           (porcentaje o monto fijo, nunca más que el precio)
 *   3. crédito         (saldo a favor por referidos; el sobrante queda para después)
 *
 * Un cobro nunca queda negativo: lo que no se alcanza a descontar sigue
 * disponible para el período siguiente.
 */
class CalculadoraDeCobro
{
    /**
     * Simula el cobro de un período sin escribir nada.
     *
     * @return array<string,mixed>
     */
    public static function simular(int $companyId, ?string $periodoInicio = null): array
    {
        $suscripcion = SuscripcionDeEmpresa::asegurar($companyId);

        if (!$suscripcion) {
            return ['ok' => false, 'motivo' => 'La consola todavía no está migrada.'];
        }

        $plan = PlanesDeLaPlataforma::buscar($suscripcion->plan_id ? (int) $suscripcion->plan_id : null);

        if (!$plan) {
            return ['ok' => false, 'motivo' => 'Esta empresa no tiene plan asignado. Asignale uno antes de cobrarle.'];
        }

        $ciclo  = $suscripcion->ciclo ?: 'mensual';
        $inicio = $periodoInicio ?: SuscripcionDeEmpresa::siguientePeriodo($suscripcion);
        $fin    = SuscripcionDeEmpresa::finDePeriodo($inicio, $ciclo);

        // 1. Precio pactado o el de lista del plan.
        $lista    = PlanesDeLaPlataforma::precioDe($plan, $ciclo);
        $pactado  = $suscripcion->precio_pactado !== null ? (float) $suscripcion->precio_pactado : null;
        $precio   = $pactado ?? $lista;

        if ($precio === null) {
            return ['ok' => false, 'motivo' => 'El plan ' . $plan->nombre . ' no tiene precio para el ciclo ' . $ciclo . '. Ingrese un precio pactado.'];
        }

        $renglones = [[
            'concepto' => 'Plan ' . $plan->nombre . ' (' . ($ciclo === 'anual' ? 'anual' : 'mensual') . ')',
            'monto'    => round($precio, 2),
        ]];

        // 2. Cupón, si la suscripción tiene uno con períodos disponibles.
        $cupon     = null;
        $descuento = 0.0;

        if ($suscripcion->cupon_id && Cupones::hayTabla()) {
            $cupon = DB::table('plataforma_cupones')->where('id', $suscripcion->cupon_id)->first();
            $vivo  = $cupon && ($suscripcion->cupon_permanente || ($suscripcion->cupon_periodos ?? 0) > 0);

            if ($vivo) {
                $descuento   = Cupones::descuentoSobre($cupon, $precio);
                $renglones[] = [
                    'concepto' => 'Cupón ' . $cupon->codigo . ' (' . ($cupon->tipo === 'porcentaje' ? rtrim(rtrim(number_format((float) $cupon->valor, 2, '.', ''), '0'), '.') . '%' : 'monto fijo') . ')',
                    'monto'    => -$descuento,
                ];
            } else {
                $cupon = null;
            }
        }

        $subtotal = round($precio - $descuento, 2);

        // 3. Crédito por referidos, hasta donde llegue.
        $saldo    = SuscripcionDeEmpresa::credito($companyId);
        $credito  = round(min(max($saldo, 0), $subtotal), 2);
        $sobrante = round(max($saldo, 0) - $credito, 2);

        if ($credito > 0) {
            $renglones[] = ['concepto' => 'Crédito por referidos', 'monto' => -$credito];
        }

        $total = round(max(0, $subtotal - $credito), 2);

        return [
            'ok'               => true,
            'company_id'       => $companyId,
            'plan'             => ['id' => (int) $plan->id, 'clave' => $plan->clave, 'nombre' => $plan->nombre],
            'ciclo'            => $ciclo,
            'periodo_inicio'   => $inicio,
            'periodo_fin'      => $fin,
            'precio_lista'     => $lista === null ? null : round($lista, 2),
            'precio_pactado'   => $pactado === null ? null : round($pactado, 2),
            'precio'           => round($precio, 2),
            'cupon'            => $cupon ? ['id' => (int) $cupon->id, 'codigo' => $cupon->codigo, 'tipo' => $cupon->tipo, 'valor' => (float) $cupon->valor] : null,
            'descuento'        => $descuento,
            'subtotal'         => $subtotal,
            'credito_saldo'    => round(max($saldo, 0), 2),
            'credito_aplicado' => $credito,
            'credito_sobrante' => $sobrante,
            'total'            => $total,
            'renglones'        => $renglones,
            'ya_cobrado'       => DB::table('plataforma_cobros')
                ->where('company_id', $companyId)->where('periodo_inicio', $inicio)
                ->where('estado', '!=', 'anulado')->exists(),
        ];
    }
}
