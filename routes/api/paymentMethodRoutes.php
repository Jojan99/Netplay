<?php

use App\Http\Controllers\PaymentMethodController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment-methods')->middleware('role:admin,contador')->group(function () {
    Route::get('/',         [PaymentMethodController::class, 'index']);
    Route::get('/active',   [PaymentMethodController::class, 'active']);
    Route::get('/summary',  [PaymentMethodController::class, 'summary']);
    Route::get('/payments', [PaymentMethodController::class, 'payments']);
    Route::post('/',        [PaymentMethodController::class, 'store']);
    Route::put('/{id}',     [PaymentMethodController::class, 'update']);
    Route::patch('/{id}/toggle', [PaymentMethodController::class, 'toggle']);
    Route::delete('/{id}',  [PaymentMethodController::class, 'destroy']);
});
