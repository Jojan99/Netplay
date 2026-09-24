<?php

namespace App\Services\Comprobantes;

use App\Models\PaymentProof;
use Illuminate\Support\Facades\DB;

/**
 * ¿Este comprobante se puede aplicar sin que nadie lo mire?
 *
 * La idea no es aprobar más, es dejar de hacerle perder el tiempo a alguien
 * con los que no tienen nada que decidir: el cliente identificado, la factura
 * clara, el monto leído sin dudas y la referencia nueva. Ésos se aplican solos
 * y quedan anotados como tales; el resto sigue yendo a revisión, que es donde
 * el ojo humano sirve de algo.
 *
 * Se exige TODO. Cualquier duda manda a revisión: aplicar de más un pago que
 * no era cuesta mucho más que mirar un comprobante.
 */
class ComprobanteConfiable
{
    /** Un pago de hace más de esto no se aplica solo: puede ser un reenvío. */
    private const DIAS_DE_GRACIA = 30;

    /**
     * Cuánto puede pasarse del saldo sin levantar sospecha.
     *
     * Algo de más es normal —el cliente redondea, o paga el mes que viene—,
     * pero el doble ya es otra cosa y conviene mirarlo.
     */
    private const VECES_LA_DEUDA = 2.0;

    /**
     * @return array{puede: bool, monto: ?float, motivos: list<string>}
     *         «motivos» es por qué NO se puede; vacío cuando sí.
     */
    public static function revisar(PaymentProof $p): array
    {
        $motivos = [];

        if (!$p->user_id) {
            $motivos[] = 'no se sabe de qué cliente es';
        }

        $factura = $p->invoice;

        if (!$factura) {
            $motivos[] = 'no está claro a qué factura va';
        }

        $monto = (float) ($p->reported_amount ?? $p->detected_amount ?? 0);

        if ($monto <= 0) {
            $motivos[] = 'no se pudo leer el monto';
        }

        // Lo que el lector no pudo confirmar. Con el monto en duda no se aplica
        // nada: es el único dato del que depende la plata.
        $dudosos = (array) (($p->raw_payload ?? [])['dudosos'] ?? []);

        if (in_array('amount', $dudosos, true)) {
            $motivos[] = 'el monto no se pudo confirmar en la imagen';
        }

        if (!$p->reference_number) {
            $motivos[] = 'sin número de referencia';
        } elseif (self::referenciaRepetida($p)) {
            // Lo más parecido a un fraude que vemos: el mismo comprobante
            // mandado dos veces. La referencia es del banco y no se repite.
            $motivos[] = 'esa referencia ya se usó en otro comprobante';
        }

        if ($p->payment_date && $p->payment_date->lt(now()->subDays(self::DIAS_DE_GRACIA))) {
            $motivos[] = 'el pago es de hace más de ' . self::DIAS_DE_GRACIA . ' días';
        }

        if ($factura && $monto > 0) {
            $deuda = max(0, (float) ($factura->price_total ?? 0)
                - (float) ($factura->price_discount ?? 0)
                - (float) ($factura->price_abone ?? 0));

            if ($deuda > 0 && $monto > $deuda * self::VECES_LA_DEUDA) {
                $motivos[] = 'el monto es mucho mayor que lo que debe';
            }
        }

        return ['puede' => $motivos === [], 'monto' => $monto > 0 ? $monto : null, 'motivos' => $motivos];
    }

    /** ¿Otro comprobante de la empresa ya usó esta referencia? */
    private static function referenciaRepetida(PaymentProof $p): bool
    {
        return DB::table('payment_proofs')
            ->where('company_id', $p->company_id)
            ->where('reference_number', $p->reference_number)
            ->where('id', '!=', $p->id)
            ->whereIn('status', ['approved', 'auto_approved'])
            ->exists();
    }
}
