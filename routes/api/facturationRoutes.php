<?php

use App\Http\Controllers\FacturationController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::prefix('facturation')->group(function () {
    // Rutas públicas (sin JWT) usadas por portal de clientes
    Route::post('getDateFacturePending', [FacturationController::class, 'getDateFacturePending'])->withoutMiddleware('jwt.verify');
    Route::post('getDatePayFacture', [FacturationController::class, 'getDatePayFacture'])->withoutMiddleware('jwt.verify');

    // Comando automático (sin autenticación, usado por cron)
    Route::get('ejecutar-comando', function () {
        Artisan::call('post:create');
        return response()->json(['message' => 'Comando ejecutado correctamente']);
    })->withoutMiddleware(['jwt.verify', 'auth:api']);

    // Operaciones de facturación: admin y contador
    Route::middleware('role:admin,contador')->group(function () {
        Route::post('createDetFacturation', [FacturationController::class, 'createDetFacturation']);
        Route::post('updateDetFacturation', [FacturationController::class, 'updateDetFacturation']);
        Route::post('createpaidFacturation', [FacturationController::class, 'createpaidFacturation']);
        Route::post('createDiscountFacturation', [FacturationController::class, 'createDiscountFacturation']);
        Route::post('createAboneFacturation', [FacturationController::class, 'createAboneFacturation']);
        Route::get('getDataInfoPenddingFacture', [FacturationController::class, 'getDataInfoPenddingFacture']);
    });
});
