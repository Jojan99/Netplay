<?php

use App\Http\Controllers\ErpRecaudaController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PaymentLinkController;
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
|   GET  /api/payment-gateway/efipay/offices
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
| Rutas públicas — links de pago compartibles (WhatsApp, correo, panel):
|   GET  /api/pay/{token}
|
| Ruta pública — cobro ERP de EfiPay (ellos consultan qué debe una cédula):
|   GET  /api/erp/efipay/{company_slug}/{token}?id_number=…
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
    Route::get('efipay/offices',    [PaymentGatewayController::class, 'efipayOffices']);
    Route::get('test-users',        [PaymentGatewayController::class, 'testUsers']);
    Route::post('test-invoice',     [PaymentGatewayController::class, 'testInvoice']);
});

// ── Links de pago compartibles (público — sin JWT) ───────────────────────────
// El token tiene 40 caracteres aleatorios; el throttle solo acota el ruido.
Route::get('pay/{token}', [PaymentLinkController::class, 'open'])
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{20,64}');

// Retorno de la pasarela: devuelve al cliente por donde entró (chat o portal).
Route::get('pay/result/{reference}', [PaymentLinkController::class, 'result'])
    ->middleware('throttle:60,1')
    ->where('reference', '[A-Za-z0-9\\-]{5,80}');

// ── Factura del cliente por enlace firmado (público — sin JWT) ───────────────
// La ruta vieja pide solo el número de factura, y los números son correlativos:
// cualquiera podía bajarse las de todos los clientes. Este enlace lleva firma.
Route::get('factura/{token}', [\App\Http\Controllers\InvoiceLinkController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('token', '[0-9]+-[a-f0-9]{24}');

// Estado de cuenta del cliente: enlace firmado, se comparte por WhatsApp
Route::get('estado-cuenta/{token}', [\App\Http\Controllers\ClientStatementController::class, 'publicView'])
    ->where('token', '[0-9]+-[a-f0-9]{24}');

// ── Cobro ERP de EfiPay (público — sin JWT) ──────────────────────────────────
// EfiPay consulta qué debe una cédula y cobra por su cuenta; el pago vuelve por
// el webhook de siempre. La documentación no define autenticación para esta URL,
// así que el secreto va en la propia ruta y sin él no resuelve.
// La documentación dice GET y el panel de EfiPay dice POST: se aceptan ambos.
Route::match(['get', 'post'], 'erp/efipay/{company_slug}/{token}', [ErpRecaudaController::class, 'recaudas'])
    ->middleware('throttle:60,1')
    ->where('company_slug', '[A-Za-z0-9\\-_]{2,64}')
    ->where('token', '[a-f0-9]{40}');

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
