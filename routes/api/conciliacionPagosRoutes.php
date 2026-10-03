<?php

use App\Http\Controllers\ConciliacionPagosController;
use Illuminate\Support\Facades\Route;

// Conciliación de pagos: una lista «nombre y valor» → cliente → facturas (módulo «finanzas»).
Route::prefix('conciliacion-pagos')->middleware('role:admin,contador')->group(function () {
    Route::post('cruzar',   [ConciliacionPagosController::class, 'cruzar']);
    Route::get('clientes',  [ConciliacionPagosController::class, 'clientes']);
    Route::post('simular',  [ConciliacionPagosController::class, 'simular']);
    Route::post('aplicar',  [ConciliacionPagosController::class, 'aplicar']);
    Route::get('lotes',     [ConciliacionPagosController::class, 'lotes']);
    Route::get('lotes/{lote}',            [ConciliacionPagosController::class, 'lote'])->where('lote', '[A-Za-z0-9-]+');
    Route::post('lotes/{lote}/revertir',  [ConciliacionPagosController::class, 'revertir'])->where('lote', '[A-Za-z0-9-]+');
    Route::post('lotes/{lote}/reforzar-duplicados', [ConciliacionPagosController::class, 'reforzarDuplicados'])->where('lote', '[A-Za-z0-9-]+');
});
