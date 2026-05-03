<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InstallationOrderController;

Route::prefix('installations')->group(function () {
    Route::get('/', [InstallationOrderController::class, 'index']);
    Route::post('/', [InstallationOrderController::class, 'store']);
    Route::get('/dashboard', [InstallationOrderController::class, 'dashboard']);
    Route::get('/plans', [InstallationOrderController::class, 'plans']);
    Route::get('/payment-methods', [InstallationOrderController::class, 'paymentMethods']);
    Route::get('/technicians', [InstallationOrderController::class, 'availableTechnicians']);
    Route::get('/{id}', [InstallationOrderController::class, 'show']);
    Route::put('/{id}', [InstallationOrderController::class, 'update']);
    Route::delete('/{id}', [InstallationOrderController::class, 'destroy']);
    
    Route::post('/{id}/confirm', [InstallationOrderController::class, 'confirm']);
    Route::post('/{id}/start', [InstallationOrderController::class, 'start']);
    Route::post('/{id}/complete', [InstallationOrderController::class, 'complete']);
    Route::post('/{id}/cancel', [InstallationOrderController::class, 'cancel']);
    
    Route::put('/{id}/payment', [InstallationOrderController::class, 'updatePayment']);
    Route::put('/{id}/technicians', [InstallationOrderController::class, 'assignTechnicians']);
    Route::get('/{id}/commission', [InstallationOrderController::class, 'calculateCommission']);
    
    Route::get('/{id}/logs', [InstallationOrderController::class, 'logs']);
    Route::post('/{id}/logs', [InstallationOrderController::class, 'createLog']);
});