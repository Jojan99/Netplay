<?php

namespace App\Console\Commands;

use App\Http\Controllers\PaymentGatewayController;
use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Services\PaymentGateways\PaymentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta a la pasarela por los pagos que quedaron colgados.
 *
 * La plataforma se entera de un pago por el aviso (webhook) de la pasarela, o
 * porque el cliente vuelve a la página de retorno. Las dos cosas fallan solas:
 * el aviso puede no estar configurado —o perderse—, y el cliente puede cerrar
 * el navegador apenas paga. Cuando eso pasa, el cliente pagó, la plataforma no
 * lo sabe, y la cobranza lo sigue persiguiendo.
 *
 * Pasó de verdad en una prueba: Wompi decía "aprobado" y la plataforma seguía
 * en "pendiente", sin un solo registro del aviso.
 *
 * Esto cierra ese hueco: cada pocos minutos mira las transacciones pendientes
 * recientes, le pregunta a la pasarela cómo terminaron, y acredita las que se
 * aprobaron. Idempotente: acreditar dos veces la misma no suma dos pagos,
 * porque la acreditación se hace contra la misma referencia.
 */
class ConciliarPagosEnLinea extends Command
{
    protected $signature = 'pagos:conciliar
        {--horas=48 : Hasta cuántas horas atrás mirar}
        {--vencen=30 : A los cuántos minutos se da por vencido un cobro que nadie aprobó}';

    protected $description = 'Revisa en la pasarela los pagos que quedaron pendientes y acredita los aprobados';

    /**
     * Hasta cuántas horas atrás se le avisa al cliente de un pago que no salió.
     *
     * Más viejo que esto se corrige en la base sin escribirle: un mensaje por
     * algo que intentó ayer no le aclara nada.
     */
    private const AVISAR_HASTA_HORAS = 6;

    public function handle(): int
    {
        $desde = now()->subHours(max(1, (int) $this->option('horas')));

        // Los vencidos se siguen consultando: un cobro puede aprobarse tarde,
        // y darlo por perdido de este lado no lo cancela en la pasarela. Si
        // entra, hay que acreditarlo igual.
        $pendientes = OnlinePaymentTransaction::whereIn('status', ['pending', 'expired'])
            ->where('created_at', '>=', $desde)
            ->whereNotNull('gateway_transaction_id')
            ->orderBy('id')
            ->limit(200)
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay pagos pendientes por revisar.');

            return self::SUCCESS;
        }

        $acreditados = 0;
        $vencidos    = 0;

        foreach ($pendientes as $tx) {
            try {
                $antes = $tx->status;
                $acreditados += $this->revisar($tx) ? 1 : 0;
                $vencidos += ($antes === 'pending' && $tx->fresh()?->status === 'expired') ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[Conciliación] No se pudo revisar un pago', [
                    'transaccion' => $tx->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("{$pendientes->count()} revisados · {$acreditados} acreditados · {$vencidos} vencidos.");

        return self::SUCCESS;
    }

    /**
     * Le cuenta al cliente que el cobro no prosperó.
     *
     * Un aviso que falla no puede tumbar la conciliación: lo importante es
     * acreditar los pagos buenos.
     */
    private function avisar(Company $company, OnlinePaymentTransaction $tx, string $estado): void
    {
        try {
            (new PaymentNotificationService())->notify($company, $tx->fresh(), $estado, ['origen' => 'conciliacion']);
        } catch (\Throwable $e) {
            Log::warning('[Conciliación] No se pudo avisar del pago no completado', [
                'transaccion' => $tx->id, 'estado' => $estado, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return bool Si se acreditó. */
    private function revisar(OnlinePaymentTransaction $tx): bool
    {
        $company = Company::find($tx->company_id);

        if (!$company) {
            return false;
        }

        $gateway = PaymentGatewayFactory::make($company);

        if (!method_exists($gateway, 'consultarTransaccion')) {
            return false;
        }

        $r = $gateway->consultarTransaccion((string) $tx->gateway_transaction_id);

        if (!$r) {
            return false;
        }

        $estado = strtolower((string) $r['status']);

        // Sigue esperando al cliente. Pasado un rato se da por vencido y se le
        // avisa: quedarse callado es lo peor, porque el cliente cree que pagó.
        if ($estado === 'pending') {
            $minutos = max(5, (int) $this->option('vencen'));

            if ($tx->status === 'pending' && $tx->created_at->lt(now()->subMinutes($minutos))) {
                $tx->update(['status' => 'expired']);

                // Sólo se avisa de lo reciente. Escribirle a alguien por un
                // cobro que abandonó ayer no le aclara nada y lo desconcierta,
                // y la primera corrida de esto encontraría una pila de viejos.
                if ($tx->created_at->gt(now()->subHours(self::AVISAR_HASTA_HORAS))) {
                    $this->avisar($company, $tx, 'expired');
                }
            }

            return false;
        }

        if ($estado !== 'approved') {
            $yaAvisado = $tx->status === 'expired';
            $reciente  = $tx->created_at->gt(now()->subHours(self::AVISAR_HASTA_HORAS));
            $tx->update(['status' => $estado]);

            if (!$yaAvisado && $reciente) {
                $this->avisar($company, $tx, $estado);
            }

            return false;
        }

        $monto = (float) ($r['amount'] ?: $tx->amount);

        app(PaymentGatewayController::class)->markInvoicePaid(
            (int) $tx->company_id,
            (string) $tx->reference,
            $monto,
            (string) $tx->gateway,
        );

        $tx->update(['status' => 'approved', 'paid_at' => now(), 'allocation_done' => true]);

        Log::info('[Conciliación] Pago acreditado sin aviso de la pasarela', [
            'transaccion' => $tx->id, 'referencia' => $tx->reference, 'monto' => $monto,
        ]);

        // El cliente merece enterarse igual que si el aviso hubiera llegado.
        (new PaymentNotificationService())->notify($company, $tx->fresh(), 'approved', ['origen' => 'conciliacion']);

        return true;
    }
}
