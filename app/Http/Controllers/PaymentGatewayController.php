<?php

namespace App\Http\Controllers;

use App\Models\CabFacturation;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\EfiPayGateway;
use App\Services\PaymentGateways\EPaycoGateway;
use App\Services\PaymentGateways\PaymentAllocationService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentGatewayController extends Controller
{
    // ─── Admin: configuración ─────────────────────────────────────────────────

    public function getConfig(): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());

        // URL del webhook específica para esta empresa (para configurar en el panel de cada pasarela)
        $webhookUrl = $company->pg_gateway && $company->slug
            ? url('/api/webhooks/' . $company->pg_gateway . '/' . $company->slug)
            : null;

        return response()->json([
            'status' => 0,
            'data'   => [
                'gateway'              => $company->pg_gateway,
                'sandbox'              => $company->pg_sandbox,
                'active'               => $company->pg_active,
                // Flags de existencia
                'has_public_key'       => !empty($company->pg_public_key),
                'has_private_key'      => !empty($company->pg_private_key),
                'has_events_secret'    => !empty($company->pg_events_secret),
                'has_integrity_secret' => !empty($company->pg_integrity_secret),
                'has_client_id'        => !empty($company->pg_client_id),
                'has_office_id'        => !empty($company->pg_office_id),
                // Valores actuales (solo admin autenticado)
                'public_key'           => $company->pg_public_key,
                'private_key'          => $company->pg_private_key,
                'events_secret'        => $company->pg_events_secret,
                'integrity_secret'     => $company->pg_integrity_secret,
                'client_id'            => $company->pg_client_id,
                'office_id'            => $company->pg_office_id,
                'webhook_url'          => $webhookUrl,
                // Permite al panel mostrar la URL de la pasarela seleccionada
                // antes de guardar la configuración.
                'webhook_base'         => url('/api/webhooks'),
                'company_slug'         => $company->slug,
                'available'            => PaymentGatewayFactory::availableGateways(),
            ],
        ]);
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $request->validate([
            'gateway'   => 'required|in:wompi,epayco,zonapago,efipay',
            'sandbox'   => 'boolean',
            'active'    => 'boolean',
            'office_id' => 'nullable|string|max:32',
        ]);

        $company = Company::findOrFail(getSessionCompanyId());

        $data = [
            'pg_gateway' => $request->input('gateway'),
            'pg_sandbox' => $request->boolean('sandbox', true),
            'pg_active'  => $request->boolean('active', false),
        ];

        foreach (['public_key', 'private_key', 'events_secret', 'integrity_secret', 'client_id', 'office_id'] as $f) {
            if ($request->filled($f)) {
                $data["pg_{$f}"] = $request->input($f);
            }
        }

        $company->update($data);

        return response()->json(['status' => 0, 'message' => 'Configuración guardada correctamente.']);
    }

    // ─── Admin: sucursales de EfiPay ──────────────────────────────────────────

    /**
     * GET /api/payment-gateway/efipay/offices
     * Lista las sucursales del comercio para que el administrador elija un
     * `office` válido en lugar de adivinarlo.
     */
    public function efipayOffices(): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());

        try {
            $offices = (new EfiPayGateway($company))->fetchOffices();
        } catch (\Throwable $e) {
            return response()->json(['status' => 1, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['status' => 0, 'data' => $offices]);
    }

    // ─── Admin: transacciones ─────────────────────────────────────────────────

    public function transactions(): JsonResponse
    {
        $txs = OnlinePaymentTransaction::where('company_id', getSessionCompanyId())
            ->orderByDesc('initiated_at')
            ->limit(300)
            ->get(['id','reference','gateway','sandbox','amount','status',
                   'customer_name','customer_email','gateway_transaction_id',
                   'initiated_at','paid_at']);

        return response()->json(['status' => 0, 'data' => $txs]);
    }

    public function transactionDetail(int $id): JsonResponse
    {
        $tx = OnlinePaymentTransaction::where('id', $id)
            ->where('company_id', getSessionCompanyId())
            ->firstOrFail();

        $invoice = null;
        if ($tx->det_facturation_id) {
            $invoice = DB::table('det_facturations as df')
                ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
                ->where('df.id', $tx->det_facturation_id)
                ->select('df.id', 'df.number_facture', 'df.price_total', 'df.paid', 'df.paid_at', 'cf.user_id')
                ->first();
        }

        return response()->json([
            'status' => 0,
            'data'   => array_merge($tx->toArray(), ['invoice' => $invoice]),
        ]);
    }

    // ─── Admin: factura de prueba ─────────────────────────────────────────────

    public function testUsers(): JsonResponse
    {
        $companyId = getSessionCompanyId();

        $users = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->join('profiles as p', 'p.id', '=', 'u.profile_id')
            ->join('cab_facturations as cf', function ($j) use ($companyId) {
                $j->on('cf.user_id', '=', 'ud.user_id')->where('cf.company_id', $companyId);
            })
            ->where('ud.company_id', $companyId)
            ->where('p.name', 'USER')
            ->orderBy('ud.names')
            ->limit(200)
            ->get(['ud.user_id as id', 'ud.names', 'ud.lastname', 'ud.email']);

        return response()->json(['status' => 0, 'data' => $users]);
    }

    public function testInvoice(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'     => 'required|integer',
            'amount'      => 'required|numeric|min:100',
            'description' => 'nullable|string|max:120',
        ]);

        $companyId = getSessionCompanyId();
        $company   = Company::findOrFail($companyId);

        if (!$company->pg_active || !$company->pg_gateway) {
            return response()->json(['status' => 1, 'message' => 'Pasarela no configurada o inactiva.'], 400);
        }

        $cab = CabFacturation::where('user_id', $request->integer('user_id'))
            ->where('company_id', $companyId)
            ->first();

        if (!$cab) {
            return response()->json(['status' => 1, 'message' => 'El usuario no tiene registro de facturación.'], 400);
        }

        $numberFacture = 'TEST' . date('ymdHis');
        $amount        = (float) $request->input('amount');
        $description   = $request->input('description') ?: "Factura prueba #{$numberFacture}";

        $det = DetFacturation::create([
            'cab_id'                  => $cab->id,
            'date_facturation'        => now()->toDateString(),
            'number_facture'          => $numberFacture,
            'date_create_facturation' => now()->toDateString(),
            'total'                   => 1,
            'price_total'             => $amount,
            'porcentage_discount'     => 0,
            'days_facture'            => 1,
            'discount'                => 0,
            'price_discount'          => 0,
            'create_facture_manual'   => 1,
            'paid'                    => 0,
        ]);

        $ud           = DB::table('user_data')->where('user_id', $request->integer('user_id'))->first();
        $customerName  = trim(($ud->names ?? '') . ' ' . ($ud->lastname ?? '')) ?: 'Test';
        $customerEmail = $ud->email ?? '';

        $reference = $company->slug . '-' . $numberFacture . '-' . $det->id;

        try {
            $gateway    = PaymentGatewayFactory::make($company);
            $paymentUrl = $gateway->generatePaymentLink([
                'reference'      => $reference,
                'amount'         => $amount,
                'description'    => $description,
                'customer_email' => $customerEmail,
                'customer_name'  => $customerName,
                'redirect_url'   => url('/portal/facturas'),
            ]);
        } catch (\Throwable $e) {
            // La factura temporal ya no sirve para nada si no hay link de pago.
            $det->delete();

            // Pantalla de admin: mostramos el motivo real que devuelve la pasarela.
            return response()->json(['status' => 1, 'message' => $e->getMessage()], 400);
        }

        OnlinePaymentTransaction::create([
            'company_id'             => $company->id,
            'det_facturation_id'     => $det->id,
            'reference'              => $reference,
            'gateway'                => $company->pg_gateway,
            'sandbox'                => $company->pg_sandbox,
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
                'invoice_id'     => $det->id,
                'number_facture' => $numberFacture,
                'amount'         => $amount,
                'reference'      => $reference,
                'payment_url'    => $paymentUrl,
                'gateway'        => $company->pg_gateway,
                'sandbox'        => $company->pg_sandbox,
                'customer_name'  => $customerName,
                'customer_email' => $customerEmail,
            ],
        ]);
    }

    // ─── ePayco: página intermedia con form auto-submit ───────────────────────

    public function epaycoCheckout(string $token): Response
    {
        try {
            $payload  = json_decode(Crypt::decryptString(urldecode($token)), true);
            $company  = Company::findOrFail($payload['company_id']);
            $gateway  = new EPaycoGateway($company);
            $formData = $gateway->buildFormData($payload);
        } catch (\Throwable) {
            abort(400, 'Token inválido o expirado.');
        }

        return response(view('payment.epayco_checkout', compact('formData'))->render());
    }

    // ─── Webhooks ─────────────────────────────────────────────────────────────

    // Rutas con slug: POST /api/webhooks/wompi/{slug}
    public function webhookWompi(Request $request, string $companySlug = null): JsonResponse
    {
        return $this->processWebhook($request, 'wompi', $companySlug);
    }

    public function webhookEpayco(Request $request, string $companySlug = null): JsonResponse
    {
        return $this->processWebhook($request, 'epayco', $companySlug);
    }

    public function webhookZonapago(Request $request, string $companySlug = null): JsonResponse
    {
        return $this->processWebhook($request, 'zonapago', $companySlug);
    }

    public function webhookEfipay(Request $request, string $companySlug = null): JsonResponse
    {
        return $this->processWebhook($request, 'efipay', $companySlug);
    }

    private function processWebhook(Request $request, string $gatewayName, ?string $companySlug = null): JsonResponse
    {
        try {
            $company = null;

            // 1. Prioridad: slug en la URL → búsqueda directa y sin ambigüedad
            if ($companySlug) {
                $company = Company::where('slug', $companySlug)
                    ->where('pg_gateway', $gatewayName)
                    ->where('pg_active', true)
                    ->first();
            }

            // 2. Fallback: buscar por referencia de transacción (multi-empresa seguro)
            if (!$company) {
                $refGuess = match ($gatewayName) {
                    'wompi'    => $request->input('data.transaction.reference'),
                    'epayco'   => $request->input('x_id_factura') ?: $request->input('x_ref_payco'),
                    'zonapago' => $request->input('referencia'),
                    'efipay'   => $request->input('checkout.payment_gateway.advanced_option.references.0'),
                    default    => null,
                };

                if ($refGuess) {
                    $tx = OnlinePaymentTransaction::where('reference', $refGuess)->first();
                    if ($tx) {
                        $company = Company::find($tx->company_id);
                    }
                }
            }

            // 3. Último recurso: única empresa activa con ese gateway
            if (!$company) {
                $company = Company::where('pg_gateway', $gatewayName)
                    ->where('pg_active', true)
                    ->first();
            }

            if (!$company) {
                Log::warning("Webhook {$gatewayName}: empresa no encontrada", [
                    'slug' => $companySlug, 'ip' => $request->ip(),
                ]);
                return response()->json(['ok' => false], 200);
            }

            $gateway = PaymentGatewayFactory::make($company);

            if (!$gateway->verifyWebhook($request)) {
                Log::warning("Webhook {$gatewayName}: firma inválida", ['ip' => $request->ip()]);
                return response()->json(['ok' => false], 200);
            }

            $reference = $gateway->getInvoiceReference($request);
            $txStatus  = $gateway->getTransactionStatus($request);
            $amount    = $txStatus === 'approved' ? $gateway->getAmountPaid($request) : 0.0;

            $gatewayTxId = match ($gatewayName) {
                'wompi'  => $request->input('data.transaction.id'),
                'epayco' => $request->input('x_ref_payco'),
                'efipay' => $request->input('checkout.pivot.transaction_id')
                            ?? $request->input('transaction.transaction_id'),
                default  => null,
            };

            // La transacción debe existir y pertenecer a la empresa notificada:
            // evita que un webhook de una empresa cierre facturas de otra.
            $tx = OnlinePaymentTransaction::where('reference', $reference)
                ->where('company_id', $company->id)
                ->first();

            if (!$tx) {
                Log::warning("Webhook {$gatewayName}: referencia desconocida para la empresa", [
                    'reference'  => $reference,
                    'company_id' => $company->id,
                    'ip'         => $request->ip(),
                ]);
                return response()->json(['ok' => false], 200);
            }

            // Siempre persistir el estado (aprobado, rechazado, cancelado, fallido…)
            $tx->update([
                'status'                 => $txStatus,
                // No borrar el id que ya se guardó al generar el link.
                'gateway_transaction_id' => $gatewayTxId ?: $tx->gateway_transaction_id,
                'gateway_payload'        => $request->all(),
                'paid_at'                => $txStatus === 'approved' ? now() : $tx->paid_at,
            ]);

            if ($txStatus === 'approved') {
                $this->markInvoicePaid($company->id, $reference, $amount, $gatewayName);
            }

            return response()->json(['ok' => true, 'status' => $txStatus], 200);

        } catch (\Throwable $e) {
            Log::error("Webhook {$gatewayName} error: " . $e->getMessage());
            return response()->json(['ok' => false], 200);
        }
    }

    private function markInvoicePaid(int $companyId, string $reference, float $amountPaid, string $gateway): void
    {
        app(PaymentAllocationService::class)->allocate($companyId, $reference, $amountPaid, $gateway);
    }
}
