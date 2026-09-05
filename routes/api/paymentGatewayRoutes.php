<?php

use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\Client\ClientPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pasarelas de pago
|--------------------------------------------------------------------------
|
| Rutas protegidas (jwt.verify + role:admin) — configuración:
|   GET  /api/payment-gateway/config
|   PUT  /api/payment-gateway/config
|
| Rutas públicas — webhooks (llamados por la pasarela, sin JWT):
|   POST /api/webhooks/wompi
|   POST /api/webhooks/epayco
|   POST /api/webhooks/zonapago
|   POST /api/webhooks/efipay
|
| Rutas públicas — checkout intermedio ePayco:
|   GET  /api/payment-gateway/epayco/checkout/{token}
|
| Rutas portal cliente (jwt.client):
|   POST /api/client/invoices/{id}/pay-link
*/

// ── Admin: configuración y gestión de pasarela ──────────────────────────────
Route::prefix('payment-gateway')->middleware(['jwt.verify', 'role:admin'])->group(function () {
    Route::get('config',            [PaymentGatewayController::class, 'getConfig']);
    Route::put('config',            [PaymentGatewayController::class, 'saveConfig']);
    Route::get('transactions',      [PaymentGatewayController::class, 'transactions']);
    Route::get('transactions/{id}', [PaymentGatewayController::class, 'transactionDetail']);
    Route::get('test-users',        [PaymentGatewayController::class, 'testUsers']);
    Route::post('test-invoice',     [PaymentGatewayController::class, 'testInvoice']);
});

// ── Checkout intermedio ePayco (público — sin JWT) ───────────────────────────
Route::get('payment-gateway/epayco/checkout/{token}', [PaymentGatewayController::class, 'epaycoCheckout']);

// ── Webhooks (públicos — llamados por las pasarelas) ─────────────────────────
// URL preferida (por empresa): POST /api/webhooks/{gateway}/{company_slug}
// URL legacy (fallback):       POST /api/webhooks/{gateway}
Route::prefix('webhooks')->group(function () {
    // Throttle: acota intentos de fuerza bruta contra la verificación de firma
    // sin estorbar el reintento legítimo de las pasarelas.
    Route::middleware('throttle:120,1')->group(function () {
        Route::post('wompi/{company_slug}',    [PaymentGatewayController::class, 'webhookWompi']);
        Route::post('epayco/{company_slug}',   [PaymentGatewayController::class, 'webhookEpayco']);
        Route::post('zonapago/{company_slug}', [PaymentGatewayController::class, 'webhookZonapago']);
        Route::post('efipay/{company_slug}',   [PaymentGatewayController::class, 'webhookEfipay']);

        Route::post('wompi',    [PaymentGatewayController::class, 'webhookWompi']);
        Route::post('epayco',   [PaymentGatewayController::class, 'webhookEpayco']);
        Route::post('zonapago', [PaymentGatewayController::class, 'webhookZonapago']);
        Route::post('efipay',   [PaymentGatewayController::class, 'webhookEfipay']);
    });

    // 📥 Webhook oficial de Meta (WhatsApp Business API)
    Route::get('whatsapp-meta',  [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp-meta', [\App\Http\Controllers\WhatsAppWebhookController::class, 'receive']);
});

// ── Portal cliente: generar link de pago ─────────────────────────────────────
Route::prefix('client')->middleware(['jwt.client'])->group(function () {
    Route::post('invoices/{id}/pay-link', [ClientPaymentController::class, 'generatePaymentLink']);
});
