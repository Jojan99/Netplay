<?php

namespace App\Services\PaymentGateways;

use App\Models\CabFacturation;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica un pago online aprobado sobre las facturas de la transacción,
 * en orden (más antigua primero) y completando cada una antes de seguir.
 *
 * Es idempotente: la bandera `allocation_done` impide que un webhook repetido
 * (o la conciliación por API) vuelva a abonar el mismo dinero.
 */
class PaymentAllocationService
{
    /** Diferencia máxima tolerada entre lo cobrado y lo registrado (redondeos). */
    private const AMOUNT_TOLERANCE = 1.0;

    /**
     * @param string|null $medio Con qué pagó el cliente en la pasarela
     *                           (NEQUI, PSE, BANCOLOMBIA_TRANSFER…). Sin esto
     *                           todos los pagos en línea quedaban «sin método».
     */
    /**
     * El medio de pago de un cobro por pasarela.
     *
     * No se reutilizan los medios que cargó la empresa —«NEQUI 3245127869
     * JOJAN» es una cuenta propia y ahí no cayó esta plata—: se usa uno propio
     * de la pasarela, que se crea la primera vez que aparece. Queda inactivo a
     * propósito, para que no se ofrezca al cobrar a mano pero sí se vea en el
     * historial.
     */
    private function medioDePasarela(int $companyId, string $gateway, ?string $medio, ?string $banco): ?int
    {
        if (!$medio) {
            return null;
        }

        // Red de seguridad: aunque una pasarela mande «Visa ·4242», al
        // catálogo entra «Visa». Es lo que evita que la lista de formas de
        // pago se llene de una fila por tarjeta.
        $medio = \App\Services\PaymentGateways\OnePayGateway::sinInstrumento($medio);

        $legible = match (strtoupper($medio)) {
            'NEQUI'                 => 'Nequi',
            'PSE'                   => 'PSE',
            'BANCOLOMBIA_TRANSFER',
            'BANCOLOMBIA_COLLECT',
            'BANCOLOMBIA_QR',
            'BANCOLOMBIA'           => 'Bancolombia',
            'DAVIPLATA'             => 'Daviplata',
            'CARD'                  => 'Tarjeta',
            'NEQUI_PUSH'            => 'Nequi',
            default                 => ucfirst(strtolower(str_replace('_', ' ', $medio))),
        };

        $nombre = strtoupper($gateway) . ' · ' . $legible . ($banco ? " ({$banco})" : '');
        $nombre = mb_substr($nombre, 0, 100);

        try {
            $id = DB::table('payment_methods')
                ->where('company_id', $companyId)
                ->where('name', $nombre)
                ->value('id');

            return $id ?: DB::table('payment_methods')->insertGetId([
                'company_id' => $companyId,
                'name'       => $nombre,
                // Inactivo: es para leer el historial, no para elegirlo al
                // cobrar en efectivo.
                'active'     => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Pagos] No se pudo anotar el medio de la pasarela', [
                'empresa' => $companyId, 'medio' => $medio, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function allocate(int $companyId, string $reference, float $amountPaid, string $gateway, ?string $medio = null, ?string $banco = null, ?string $detalle = null): void
    {
        $tx = OnlinePaymentTransaction::where('reference', $reference)->first();
        if (!$tx) {
            Log::warning('Pago online: referencia sin transacción registrada', [
                'reference' => $reference, 'gateway' => $gateway,
            ]);
            return;
        }

        // Multi-empresa: la transacción debe pertenecer a la empresa que resolvió el webhook.
        if ((int) $tx->company_id !== $companyId) {
            Log::critical('Pago online: la transacción no pertenece a la empresa notificada', [
                'reference'      => $reference,
                'gateway'        => $gateway,
                'tx_company_id'  => $tx->company_id,
                'notified_company_id' => $companyId,
            ]);
            return;
        }

        // Idempotencia: si ya se distribuyó este pago, no volver a procesar
        if ($tx->allocation_done) return;

        // Un pago no puede abonar más de lo que se autorizó al iniciarlo.
        $expected = round((float) $tx->amount, 2);
        if ($amountPaid > $expected + self::AMOUNT_TOLERANCE) {
            Log::warning('Pago online: monto notificado mayor al autorizado, se aplica el autorizado', [
                'reference' => $reference,
                'gateway'   => $gateway,
                'notified'  => $amountPaid,
                'expected'  => $expected,
            ]);
            $amountPaid = $expected;
        }

        if ($amountPaid <= 0) return;

        // Determinar facturas a pagar (multi o single)
        $invoiceIds = !empty($tx->invoice_ids) ? $tx->invoice_ids : [$tx->det_facturation_id];
        $invoiceIds = array_values(array_filter($invoiceIds));

        if (empty($invoiceIds)) return;

        $invoices = DetFacturation::whereIn('id', $invoiceIds)->get()
            ->sortBy(fn($inv) => array_search($inv->id, $invoiceIds))
            ->values();

        $clientName  = 'Portal (online)';
        $cabResolved = false;

        // $medio y $banco faltaban aquí y se usan adentro (el medio con el que
        // pagó el cliente). En la consola eso es sólo un aviso, pero en el
        // servidor Laravel lo convierte en excepción: saltaba dentro de la
        // transacción, se deshacía todo y la factura quedaba sin acreditar
        // aunque el cliente hubiera pagado. Le pasaba a todas las pasarelas.
        DB::transaction(function () use (
            $invoices, $amountPaid, $tx, $gateway, $companyId, $medio, $banco, $detalle, &$clientName, &$cabResolved
        ) {
            // Bloqueo pesimista: dos webhooks simultáneos no pueden abonar dos veces.
            $locked = OnlinePaymentTransaction::whereKey($tx->id)->lockForUpdate()->first();
            if (!$locked || $locked->allocation_done) return;

            $remaining = round($amountPaid, 2);

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) break;

                // Resolver nombre de cliente (solo una vez)
                if (!$cabResolved) {
                    $cab = CabFacturation::find($invoice->cab_id);
                    if ($cab) {
                        $ud = DB::table('user_data')->where('user_id', $cab->user_id)->first(['names', 'lastname']);
                        if ($ud) {
                            $clientName = trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')) ?: $clientName;
                        }
                    }
                    $cabResolved = true;
                }

                // `price_abone` guarda el dinero abonado; `abone` es solo la
                // bandera 0/1 de "tiene abono". Confundirlas hacía que el pago
                // online nunca cerrara una factura.
                $alreadyPaid = $invoice->amountPaid();
                $stillOwed   = $invoice->outstanding();

                if ($stillOwed <= 0) continue;

                if ($remaining >= $stillOwed) {
                    // Pago completo de esta factura
                    $invoice->update([
                        'paid'        => 1,
                        'paid_at'     => now(),
                        'price_abone' => $invoice->netTotal(),
                        'abone'       => 1,
                    ]);
                    $applied   = $stillOwed;
                    $remaining = round($remaining - $stillOwed, 2);
                    $fullyPaid = true;
                } else {
                    // Abono parcial
                    $invoice->update([
                        'price_abone' => round($alreadyPaid + $remaining, 2),
                        'abone'       => 1,
                    ]);
                    $applied   = $remaining;
                    $remaining = 0;
                    $fullyPaid = false;
                }

                PaymentLog::create([
                    'company_id'          => $companyId,
                    'det_facturation_id'  => $invoice->id,
                    'cab_id'              => $invoice->cab_id,
                    'number_facture'      => $invoice->number_facture,
                    'client_name'         => $clientName,
                    'recorded_by_user_id' => null,
                    'amount'              => $applied,
                    // payment_logs.type es un enum: pago_completo|abono|descuento|ajuste.
                    // Escribir cualquier otra cosa hace que MySQL trunque y aborte.
                    'type'                => $fullyPaid ? 'pago_completo' : 'abono',
                    // El instrumento concreto («Mastercard ·3222») va aquí y no
                    // en el catálogo de métodos: si fuera allá, habría una
                    // forma de pago por cada tarjeta que pase por la
                    // plataforma y la lista quedaría impresentable.
                    'notes'               => 'Pago online vía ' . strtoupper($gateway)
                        . ($detalle ? " ({$detalle})" : '')
                        . ". Ref: {$locked->reference}",
                    'payment_method_id'   => $this->medioDePasarela($companyId, $gateway, $medio, $banco),
                ]);
            }

            $locked->update(['allocation_done' => true]);
        });
    }
}
