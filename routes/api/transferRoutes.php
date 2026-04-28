<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TransferOrderController;

Route::prefix('transfers')->group(function () {
    Route::get('/', [TransferOrderController::class, 'index']);
    Route::post('/', [TransferOrderController::class, 'store']);
    Route::get('/dashboard', [TransferOrderController::class, 'dashboard']);
    Route::get('/technicians', [TransferOrderController::class, 'availableTechnicians']);
    Route::get('/routers', [TransferOrderController::class, 'routers']);
    Route::get('/{id}', [TransferOrderController::class, 'show']);
    Route::put('/{id}', [TransferOrderController::class, 'update']);
    Route::delete('/{id}', [TransferOrderController::class, 'destroy']);
    
    Route::post('/{id}/confirm', [TransferOrderController::class, 'confirm']);
    Route::post('/{id}/start', [TransferOrderController::class, 'start']);
    Route::post('/{id}/complete', [TransferOrderController::class, 'complete']);
    Route::post('/{id}/cancel', [TransferOrderController::class, 'cancel']);
    
    Route::put('/{id}/payment', [TransferOrderController::class, 'updatePayment']);
    Route::put('/{id}/technicians', [TransferOrderController::class, 'assignTechnicians']);
    Route::get('/{id}/commission', [TransferOrderController::class, 'calculateCommission']);
});