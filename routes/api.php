<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::get('/payment-proofs', [\App\Http\Controllers\PaymentProofController::class, 'index']);
    Route::get('/payment-proofs/{id}', [\App\Http\Controllers\PaymentProofController::class, 'show']);
    Route::post('/payment-proofs/{id}/suspicious', [\App\Http\Controllers\PaymentProofController::class, 'markSuspicious']);
    Route::post('/payment-proofs/{id}/approve', [\App\Http\Controllers\PaymentProofController::class, 'approve']);
    Route::post('/payment-proofs/{id}/reject', [\App\Http\Controllers\PaymentProofController::class, 'reject']);
    Route::post('/payment-proofs/{id}/revert', [\App\Http\Controllers\PaymentProofController::class, 'revert']);
});
