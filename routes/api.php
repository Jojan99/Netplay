<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Comprobantes de pago: solo administración y contabilidad.
Route::middleware(['api', 'role:admin,contador'])->group(function () {
    // Va antes de '/{id}': si no, «automatico» se lee como un id.
    Route::match(['get', 'post'], '/payment-proofs/automatico', [\App\Http\Controllers\PaymentProofController::class, 'automatico']);
    Route::post('/payment-proofs/aplicar-pendientes', [\App\Http\Controllers\PaymentProofController::class, 'aplicarPendientes']);
    Route::get('/payment-proofs', [\App\Http\Controllers\PaymentProofController::class, 'index']);
    Route::get('/payment-proofs/{id}', [\App\Http\Controllers\PaymentProofController::class, 'show']);
    Route::post('/payment-proofs/{id}/suspicious', [\App\Http\Controllers\PaymentProofController::class, 'markSuspicious']);
    Route::post('/payment-proofs/{id}/approve', [\App\Http\Controllers\PaymentProofController::class, 'approve']);
    // Volver a leer la imagen: para los que entraron sin lector, o salieron borrosos.
    Route::post('/payment-proofs/{id}/releer', [\App\Http\Controllers\PaymentProofController::class, 'releer']);
    Route::post('/payment-proofs/{id}/reject', [\App\Http\Controllers\PaymentProofController::class, 'reject']);
    Route::post('/payment-proofs/{id}/revert', [\App\Http\Controllers\PaymentProofController::class, 'revert']);
});
