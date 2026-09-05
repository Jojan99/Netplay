<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CabFacturation;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\EfiPayGateway;
use App\Services\PaymentGateways\PaymentAllocationService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientPaymentController extends Controller
{
    /**
     * POST /api/client/payment/initiate
     * Genera un link de pago para un monto parcial o total sobre N facturas.
     * El sistema aplica el pago a las facturas en orden (más antigua primero), completando
     * cada una antes de continuar con la siguiente (abono inteligente).
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        $clientUser = $request->input('_client_user');
        if (!$clientUser) {
            return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);
        }

        $request->validate([
            'amount'        => 'required|numeric|min:1',
            'invoice_ids'   => 'required|array|min:1',
            'invoice_ids.*' => 'integer|min:1',
        ]);

        $amount     = round((float) $request->input('amount'), 2);
        $invoiceIds = array_values(array_unique($request->input('invoice_ids')));

        // Ownership: facturas deben pertenecer al cliente autenticado en su empresa
        $cab = CabFacturation::where('user_id', $clientUser->id)
            ->where('company_id', $clientUser->company_id)
            ->first();

        if (!$cab) {
            return response()->json(['status' => 1, 'message' => 'No se encontró registro de facturación.'], 400);
        }

        $invoices = DetFacturation::whereIn('id', $invoiceIds)
            ->where('cab_id', $cab->id)
            ->where('paid', 0)
            ->orderBy('date_facturation')
            ->orderBy('id')
            ->get();

        if ($invoices->count() !== count($invoiceIds)) {
            return response()->json(['status' => 1, 'message' => 'Una o más facturas no son válidas o ya están pagadas.'], 400);
        }

        $totalDue = $invoices->sum(fn($inv) => $inv->price_total - (float) ($inv->abone ?? 0));
        if ($amount > round($totalDue + 0.01, 2)) {
            return response()->json(['status' => 1, 'message' => 'El monto supera el total adeudado (' . number_format($totalDue, 0, ',', '.') . ').'], 400);
        }

        $company = Company::findOrFail($clientUser->company_id);
        if (!$company->pg_active || !$company->pg_gateway) {
            return response()->json(['status' => 1, 'message' => 'Pago en línea no disponible.'], 400);
        }

        // Calcular distribución del pago (preview para el frontend y para el webhook)
        $breakdown  = $this->computeBreakdown($invoices, $amount);
        $orderedIds = $invoices->pluck('id')->values()->all();

        // Referencia única: evita colisiones con múltiples intentos simultáneos
        $firstInvoice = $invoices->first();
        $tag          = count($orderedIds) > 1 ? 'MULTI' : $firstInvoice->number_facture;
        $reference    = $company->slug . '-' . $tag . '-' . time() . '-' . $firstInvoice->id;

        $userData      = DB::table('user_data')->where('user_id', $clientUser->id)->first(['names', 'lastname', 'email']);
        $customerEmail = $userData->email ?? '';
        $customerName  = trim(($userData->names ?? '') . ' ' . ($userData->lastname ?? '')) ?: ($clientUser->username ?? '');
        // El redirect_url llega del cliente: solo se acepta si apunta a nuestro
        // propio dominio, para que no pueda usarse como redirección abierta.
        $baseRedirect  = $this->safeRedirectUrl($request->input('redirect_url'));
        $redirectUrl   = rtrim($baseRedirect, '/') . '?tx=' . urlencode($reference);

        $description = count($orderedIds) > 1
            ? 'Pago ' . count($orderedIds) . ' facturas'
            : 'Factura #' . $firstInvoice->number_facture;

        try {
            $gateway = PaymentGatewayFactory::make($company);
            $link    = $gateway->generatePaymentLink([
                'reference'      => $reference,
                'amount'         => $amount,
                'description'    => $description,
                'customer_email' => $customerEmail,
                'customer_name'  => $customerName,
                'redirect_url'   => $redirectUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('Pago online: no se pudo generar el link', [
                'company_id' => $company->id,
                'gateway'    => $company->pg_gateway,
                'reference'  => $reference,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'No pudimos generar el link de pago en este momento. Intenta de nuevo en unos minutos.',
            ], 502);
        }

        OnlinePaymentTransaction::create([
            'company_id'             => $company->id,
            'det_facturation_id'     => $firstInvoice->id,
            'invoice_ids'            => $orderedIds,
            'reference'              => $reference,
            'gateway'                => $company->pg_gateway,
            'sandbox'                => (bool) $company->pg_sandbox,
            'amount'                 => $amount,
            'status'                 => 'pending',
            'customer_name'          => $customerName,
            'customer_email'         => $customerEmail,
            'gateway_transaction_id' => $gateway->getLastGatewayReference(),
            'initiated_at'           => now(),
        ]);

        return response()->json([
            'status' => 0,
            'data'   => [
                'payment_url' => $link,
                'reference'   => $reference,
                'amount'      => $amount,
                'gateway'     => $company->pg_gateway,
                'sandbox'     => $company->pg_sandbox,
                'breakdown'   => $breakdown,
            ],
        ]);
    }

    /**
     * POST /api/client/invoices/{id}/pay-link  (legacy — pago de una factura individual)
     */
    public function generatePaymentLink(int $detId, Request $request): JsonResponse
    {
        $clientUser = $request->input('_client_user');
        if (!$clientUser) {
            return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);
        }

        // Ownership: la factura debe pertenecer al cliente autenticado
        $cab = CabFacturation::where('user_id', $clientUser->id)
            ->where('company_id', $clientUser->company_id)
            ->first();

        if (!$cab) {
            return response()->json(['status' => 1, 'message' => 'No se encontró registro de facturación.'], 400);
        }

        $invoice = DetFacturation::where('id', $detId)
            ->where('cab_id', $cab->id)
            ->firstOrFail();

        if ($invoice->paid) {
            return response()->json(['status' => 1, 'message' => 'Esta factura ya está pagada.'], 400);
        }

        if (OnlinePaymentTransaction::where('det_facturation_id', $invoice->id)->where('status', 'approved')->exists()) {
            return response()->json(['status' => 1, 'message' => 'Esta factura ya fue pagada en línea.'], 400);
        }

        // Delegar a initiatePayment con amount = price_total y invoice_ids = [$detId]
        $request->merge([
            'amount'      => $invoice->price_total,
            'invoice_ids' => [$invoice->id],
        ]);

        return $this->initiatePayment($request);
    }

    /**
     * GET /api/client/payment/result/{ref}
     * Consulta el resultado de una transacción por referencia interna.
     */
    public function paymentResult(string $ref, Request $request): JsonResponse
    {
        $clientUser = $request->input('_client_user');
        if (!$clientUser) {
            return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);
        }

        $tx = OnlinePaymentTransaction::where('reference', $ref)->first()
           ?? OnlinePaymentTransaction::where('gateway_transaction_id', $ref)->first();

        if (!$tx) {
            return response()->json(['status' => 1, 'message' => 'Transacción no encontrada.'], 404);
        }

        // Verificar que la transacción pertenece al cliente
        $cab = CabFacturation::where('user_id', $clientUser->id)
            ->where('company_id', $clientUser->company_id)
            ->first();

        if (!$cab) {
            return response()->json(['status' => 1, 'message' => 'Sin acceso.'], 403);
        }

        $invoice = DetFacturation::where('id', $tx->det_facturation_id)
            ->where('cab_id', $cab->id)
            ->first();

        if (!$invoice) {
            return response()->json(['status' => 1, 'message' => 'Sin acceso.'], 403);
        }

        // Si el webhook aún no llegó, preguntamos directamente a la pasarela.
        // Así el cliente ve el resultado real sin esperar al reintento de EfiPay.
        if ($tx->status === 'pending') {
            $this->reconcilePending($tx);
            $tx->refresh();
        }

        return response()->json([
            'status' => 0,
            'data'   => [
                'tx_status'   => $tx->status,
                'amount'      => $tx->amount,
                'reference'   => $tx->reference,
                'gateway'     => $tx->gateway,
                'sandbox'     => $tx->sandbox,
                'paid_at'     => $tx->paid_at,
                'invoice_id'  => $tx->det_facturation_id,
                'invoice_ids' => $tx->invoice_ids ?? [$tx->det_facturation_id],
            ],
        ]);
    }

    /**
     * Devuelve una URL de retorno segura: la solicitada solo si comparte host
     * con la aplicación; en cualquier otro caso, el portal de facturas.
     */
    private function safeRedirectUrl(?string $requested): string
    {
        $default = url('/portal/facturas');

        if (!is_string($requested) || trim($requested) === '') {
            return $default;
        }

        $requested = trim($requested);

        if (!filter_var($requested, FILTER_VALIDATE_URL)) {
            return $default;
        }

        $host    = parse_url($requested, PHP_URL_HOST);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if (!$host || !$appHost || strcasecmp($host, $appHost) !== 0) {
            return $default;
        }

        return $requested;
    }

    /**
     * Consulta el estado real en la pasarela cuando la transacción sigue pendiente.
     * Solo EfiPay expone hoy un endpoint de estado; el resto se deja intacto.
     */
    private function reconcilePending(OnlinePaymentTransaction $tx): void
    {
        if ($tx->gateway !== 'efipay' || empty($tx->gateway_transaction_id)) {
            return;
        }

        // El portal reintenta cada 3 s; limitamos la consulta externa a una
        // cada 8 s por transacción para no saturar la API de EfiPay.
        if (!Cache::add('efipay:reconcile:' . $tx->id, 1, 8)) {
            return;
        }

        try {
            $company = Company::find($tx->company_id);
            if (!$company || !$company->pg_active) return;

            $result = (new EfiPayGateway($company))->fetchStatus((string) $tx->gateway_transaction_id);
            if (!$result || $result['status'] === 'pending') return;

            $tx->update([
                'status'  => $result['status'],
                'paid_at' => $result['status'] === 'approved' ? ($tx->paid_at ?? now()) : $tx->paid_at,
            ]);

            if ($result['status'] === 'approved') {
                // Si la consulta no trae monto, se usa el autorizado al iniciar el pago.
                $paid = $result['amount'] > 0 ? $result['amount'] : (float) $tx->amount;

                app(PaymentAllocationService::class)
                    ->allocate($company->id, $tx->reference, $paid, 'efipay');
            }
        } catch (\Throwable $e) {
            // La conciliación es best-effort: el webhook sigue siendo la vía principal.
            Log::warning('Pago online: conciliación fallida', [
                'reference' => $tx->reference,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function computeBreakdown($invoices, float $amount): array
    {
        $remaining = $amount;
        $result    = [];

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) break;

            $alreadyPaid = (float) ($invoice->abone ?? 0);
            $stillOwed   = round($invoice->price_total - $alreadyPaid, 2);
            $toPay       = min($remaining, $stillOwed);

            $result[] = [
                'invoice_id'     => $invoice->id,
                'number_facture' => $invoice->number_facture,
                'price_total'    => (float) $invoice->price_total,
                'already_paid'   => $alreadyPaid,
                'still_owed'     => $stillOwed,
                'amount_to_pay'  => round($toPay, 2),
                'full_coverage'  => $toPay >= $stillOwed - 0.01,
            ];

            $remaining = round($remaining - $toPay, 2);
        }

        return $result;
    }
}
