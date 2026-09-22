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
    protected $signature = 'pagos:conciliar {--horas=48 : Hasta cuántas horas atrás mirar}';

    protected $description = 'Revisa en la pasarela los pagos que quedaron pendientes y acredita los aprobados';

    public function handle(): int
    {
        $desde = now()->subHours(max(1, (int) $this->option('horas')));

        $pendientes = OnlinePaymentTransaction::where('status', 'pending')
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

        foreach ($pendientes as $tx) {
            try {
                $acreditados += $this->revisar($tx) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[Conciliación] No se pudo revisar un pago', [
                    'transaccion' => $tx->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("{$pendientes->count()} revisados · {$acreditados} acreditados.");

        return self::SUCCESS;
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

        if ($estado === 'pending') {
            return false;
        }

        if ($estado !== 'approved') {
            $tx->update(['status' => $estado]);

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
