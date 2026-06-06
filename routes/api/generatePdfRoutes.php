<?php

use App\Http\Controllers\GeneratePdfController;
use Illuminate\Support\Facades\Route;


Route::prefix('generatePdf')->group(function () {
    Route::post('generatePdf', [GeneratePdfController::class, 'generatePdf']);
    Route::get('generatePdfbyId/{userid}', [GeneratePdfController::class, 'generatePdfbyId'])->withoutMiddleware('jwt.verify');
    Route::get('generatePdfTicketbyId/{id}', [GeneratePdfController::class, 'generatePdfTicketbyId']);
    Route::get('generatePaidPdfbyId/{idFacture}', [GeneratePdfController::class, 'generatePaidPdfbyId']);

    // Envío individual parametrizable
    Route::post('sendInvoiceByWhatsApp/{invoiceId}', [GeneratePdfController::class, 'sendInvoiceByWhatsApp']);
    Route::post('sendInvoiceByEmail/{invoiceId}', [GeneratePdfController::class, 'sendInvoiceByEmail']);
    Route::post('sendInvoice/{invoiceId}', [GeneratePdfController::class, 'sendInvoice']);

    // Historial de envíos
    Route::get('sendHistory/{invoiceId}', [GeneratePdfController::class, 'sendHistory']);
});
