<?php

use App\Http\Controllers\Client\ClientAuthController;
use App\Http\Controllers\Client\ClientInvoiceController;
use App\Http\Controllers\Client\ClientPaymentController;
use App\Http\Controllers\Client\ClientTicketController;
use App\Http\Controllers\Client\ClientProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Portal de Cliente
|--------------------------------------------------------------------------
| Prefijo: /api/client
|
| Rutas públicas (sin JWT):
|   POST /api/client/login
|
| Rutas protegidas (jwt.client — solo profile_id = 1):
|   GET  /api/client/me
|   POST /api/client/logout
|   GET  /api/client/invoices
|   GET  /api/client/invoices/{id}
|   GET  /api/client/invoices/{id}/pdf-url
|   POST /api/client/invoices/{id}/send-whatsapp
|   GET  /api/client/tickets
|   POST /api/client/tickets
|   GET  /api/client/tickets/{id}
|   GET  /api/client/profile
|   PUT  /api/client/profile
*/

// ── Pública: Login del cliente ──────────────────────────────────────────────
Route::post('client/login', [ClientAuthController::class, 'login']);

// ── Protegidas: solo clientes (profile_id = 1) ─────────────────────────────
Route::prefix('client')->middleware(['jwt.client'])->group(function () {

    // Sesión
    Route::get('me',     [ClientAuthController::class, 'me']);
    Route::post('logout', [ClientAuthController::class, 'logout']);

    // Facturación
    Route::get('invoices',                         [ClientInvoiceController::class, 'index']);
    Route::get('invoices/{id}',                    [ClientInvoiceController::class, 'show']);
    Route::get('invoices/{id}/pdf-url',            [ClientInvoiceController::class, 'pdfUrl']);
    Route::post('invoices/{id}/send-whatsapp',     [ClientInvoiceController::class, 'sendWhatsapp']);
    Route::post('invoices/{id}/send-email',        [ClientInvoiceController::class, 'sendEmail']);
    Route::post('invoices/{id}/send',               [ClientInvoiceController::class, 'send']);
    Route::get('invoices/{id}/send-history',       [ClientInvoiceController::class, 'sendHistory']);

    // Pago online — iniciar pago (multi-factura, abono parcial)
    Route::post('payment/initiate',    [ClientPaymentController::class, 'initiatePayment']);
    // Pago online — resultado (retorno de pasarela)
    Route::get('payment/result/{ref}', [ClientPaymentController::class, 'paymentResult']);

    // Reportes de falla
    Route::get('tickets',      [ClientTicketController::class, 'index']);
    Route::post('tickets',     [ClientTicketController::class, 'store']);
    Route::get('tickets/{id}', [ClientTicketController::class, 'show']);

    // Perfil
    Route::get('profile', [ClientProfileController::class, 'show']);
    Route::put('profile', [ClientProfileController::class, 'update']);
    Route::post('change-password', [ClientProfileController::class, 'changePassword']);
});
