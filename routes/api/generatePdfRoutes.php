<?php

use App\Http\Controllers\GeneratePdfController;
use Illuminate\Support\Facades\Route;


Route::prefix('generatePdf')->group(function () {
    // Facturación masiva de la empresa en sesión: solo administración y contabilidad.
    Route::post('generatePdf', [GeneratePdfController::class, 'generatePdf'])->middleware('role:admin,contador');
    // Sólo con sesión del panel. Los clientes y WhatsApp usan /api/factura/{token}
    // (enlace firmado): los números de factura son correlativos y se adivinan.
    Route::get('generatePdfbyId/{userid}', [GeneratePdfController::class, 'generatePdfbyId']);
    Route::get('generatePdfTicketbyId/{id}', [GeneratePdfController::class, 'generatePdfTicketbyId']);
    Route::get('generatePaidPdfbyId/{idFacture}', [GeneratePdfController::class, 'generatePaidPdfbyId']);

    // Envío individual parametrizable
    Route::post('sendInvoiceByWhatsApp/{invoiceId}', [GeneratePdfController::class, 'sendInvoiceByWhatsApp']);
    Route::post('sendInvoiceByEmail/{invoiceId}', [GeneratePdfController::class, 'sendInvoiceByEmail']);
    Route::post('sendInvoice/{invoiceId}', [GeneratePdfController::class, 'sendInvoice']);

    // Historial de envíos
    Route::get('sendHistory/{invoiceId}', [GeneratePdfController::class, 'sendHistory']);

    // Logs de envío paginados (panel admin)
    Route::get('send-logs', [GeneratePdfController::class, 'sendLogs']);
});
