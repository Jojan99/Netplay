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
use App\Services\PaymentGateways\PaymentNotificationService;
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

        // URL del cobro ERP: solo EfiPay la usa, y lleva el token adentro porque
        // esa ruta no tiene otra autenticación.
        $erpUrl = $company->pg_gateway === 'efipay' && $company->slug
            ? \App\Http\Controllers\ErpRecaudaController::urlFor($company)
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
                'has_webhook_token'    => !empty($company->pg_webhook_token),
                // Valores actuales (solo admin autenticado)
                'public_key'           => $company->pg_public_key,
                'private_key'          => $company->pg_private_key,
                'events_secret'        => $company->pg_events_secret,
                'integrity_secret'     => $company->pg_integrity_secret,
                'client_id'            => $company->pg_client_id,
                'office_id'            => $company->pg_office_id,
                'webhook_token'        => $company->pg_webhook_token,
                'template_id'          => $company->pg_template_id,
                'webhook_url'          => $webhookUrl,
                'erp_url'              => $erpUrl,
                'erp_bearer'           => $erpUrl
                    ? \App\Http\Controllers\ErpRecaudaController::bearerFor($company)
                    : null,
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
            'gateway'   => 'required|in:wompi,epayco,zonapago,efipay,onepay',
            'sandbox'   => 'boolean',
            'active'    => 'boolean',
            'office_id' => 'nullable|string|max:32',
            // OnePay: el token fijo del aviso y la plantilla de WhatsApp.
            'webhook_token' => 'nullable|string|max:191',
            'template_id'   => 'nullable|integer|min:1',
        ]);

        $company = Company::findOrFail(getSessionCompanyId());

        $data = [
            'pg_gateway' => $request->input('gateway'),
            'pg_sandbox' => $request->boolean('sandbox', true),
            'pg_active'  => $request->boolean('active', false),
        ];

        foreach (['public_key', 'private_key', 'events_secret', 'integrity_secret', 'client_id', 'office_id', 'webhook_token'] as $f) {
            if ($request->filled($f)) {
                $data["pg_{$f}"] = $request->input($f);
            }
        }

        // La plantilla se puede borrar a propósito (vacío = que OnePay elija),
        // así que no entra en el bucle de «sólo si viene con algo».
        if ($request->has('template_id')) {
            $data['pg_template_id'] = $request->input('template_id') ?: null;
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

    public function webhookOnepay(Request $request, string $companySlug = null): JsonResponse
    {
        return $this->processWebhook($request, 'onepay', $companySlug);
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
                    'efipay'   => $request->input('checkout.payment_gateway.advanced_option.references.0')
                                  ?? $request->input('checkout.payment_referenceable.ref_payment'),
                    'onepay'   => $request->input('payment.reference')
                                  ?? $request->input('payment.external_id')
                                  ?? $request->input('charge.reference'),
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
                'onepay' => $request->input('payment.id') ?? $request->input('charge.id'),
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

            // Estado anterior: solo se avisa al cliente cuando de verdad cambia,
            // así los reintentos del webhook no le repiten el mensaje.
            $previousStatus = $tx->status;

            // La transacción nace en 'pending', así que un pago en efectivo
            // ("Por Pagar") no cambiaría el estado y el cliente se quedaría sin
            // aviso. El primer webhook siempre notifica.
            $firstNotice = empty($tx->gateway_payload);

            // Siempre persistir el estado (aprobado, rechazado, cancelado, fallido…)
            $tx->update([
                'status'                 => $txStatus,
                // No borrar el id que ya se guardó al generar el link.
                'gateway_transaction_id' => $gatewayTxId ?: $tx->gateway_transaction_id,
                'gateway_payload'        => $request->all(),
                'paid_at'                => $txStatus === 'approved' ? now() : $tx->paid_at,
            ]);

            if ($txStatus === 'approved') {
                // Con qué pagó: viene en el mismo aviso, y es lo que evita que
                // el movimiento quede «sin método» en el historial.
                $medio = match ($gatewayName) {
                    'wompi'  => $request->input('data.transaction.payment_method_type')
                                ?? $request->input('data.transaction.payment_method.type'),
                    'efipay' => $request->input('checkout.payment_method')
                                ?? $request->input('transaction.payment_method'),
                    // OnePay: lo resuelve la pasarela, porque su aviso no
                    // siempre trae el medio y a veces hay que ir a buscarlo.
                    'onepay' => null,
                    default  => null,
                };

                $banco = null;
                $detalle = null;

                if ($gateway instanceof \App\Services\PaymentGateways\OnePayGateway) {
                    [$medio, $banco, $detalle] = $gateway->medioYBanco($request);
                }

                $banco ??= $gatewayName === 'wompi'
                    ? ($request->input('data.transaction.payment_method.extra.bank_name')
                       ?? $request->input('data.transaction.payment_method.extra.brand'))
                    : null;

                $this->markInvoicePaid($company->id, $reference, $amount, $gatewayName, $medio, $banco, $detalle);

                // Los cobros gemelos: otros links vivos por las mismas
                // facturas. Si quedaran abiertos, el cliente que todavía
                // tiene el mensaje anterior en el chat podría pagar de nuevo
                // algo que ya pagó.
                $this->cerrarCobrosGemelos($company, $tx->fresh(), $gateway);
            }

            // Se notifica después de acreditar, para que el mensaje pueda
            // informar el saldo real de cada factura y no el que había antes.
            if ($txStatus !== $previousStatus || $firstNotice) {
                (new PaymentNotificationService())
                    ->notify($company, $tx->fresh(), $txStatus, $request->all());
            }

            return response()->json(['ok' => true, 'status' => $txStatus], 200);

        } catch (\Throwable $e) {
            Log::error("Webhook {$gatewayName} error: " . $e->getMessage());
            return response()->json(['ok' => false], 200);
        }
    }

    /**
     * Cierra los otros cobros pendientes por las mismas facturas.
     *
     * Del lado nuestro quedan como cancelados, y en la pasarela se anulan de
     * verdad cuando sabe hacerlo: así el link viejo deja de cobrar en vez de
     * quedar dando vueltas en el chat del cliente.
     */
    private function cerrarCobrosGemelos(Company $company, ?OnlinePaymentTransaction $pagada, $gateway): void
    {
        if (!$pagada) {
            return;
        }

        $suyas = array_map('intval', (array) ($pagada->invoice_ids ?? []));
        sort($suyas);

        if (!$suyas) {
            return;
        }

        $otros = OnlinePaymentTransaction::where('company_id', $company->id)
            ->where('status', 'pending')
            ->where('id', '!=', $pagada->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->filter(function (OnlinePaymentTransaction $t) use ($suyas) {
                $otras = array_map('intval', (array) ($t->invoice_ids ?? []));
                sort($otras);

                // Cualquier cobro que toque alguna de las facturas pagadas:
                // no hace falta que el conjunto sea idéntico, porque un cobro
                // por «las tres» también sobra si ya se pagaron las tres.
                return (bool) array_intersect($otras, $suyas);
            });

        foreach ($otros as $t) {
            if ($t->gateway_transaction_id && $gateway instanceof \App\Services\PaymentGateways\OnePayGateway) {
                try {
                    $gateway->api()->anularCobro((string) $t->gateway_transaction_id);
                } catch (\Throwable $e) {
                    // Que no se pueda anular allá no puede impedir cerrarlo acá.
                    Log::info('[Pagos] No se pudo anular el cobro gemelo en la pasarela', [
                        'cobro' => $t->reference, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $t->update(['status' => 'cancelled']);

            Log::info('[Pagos] Cobro gemelo cerrado', [
                'pagado'   => $pagada->reference,
                'cerrado'  => $t->reference,
                'empresa'  => $company->id,
            ]);
        }
    }

    /** Público: lo usa también la página de retorno cuando resuelve el pago sin webhook. */
    public function markInvoicePaid(int $companyId, string $reference, float $amountPaid, string $gateway, ?string $medio = null, ?string $banco = null, ?string $detalle = null): void
    {
        app(PaymentAllocationService::class)->allocate($companyId, $reference, $amountPaid, $gateway, $medio, $banco, $detalle);
    }
}
