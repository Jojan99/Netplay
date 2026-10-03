<?php

use App\Http\Controllers\FacturaElectronicaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facturación electrónica (DIAN) por Siigo o Alegra
|--------------------------------------------------------------------------
| Conectar el proveedor: sólo el administrador. Emitir y corregir: el
| controlador deja también al contador.
*/
Route::prefix('factura-electronica')->group(function () {
    Route::get('/',            [FacturaElectronicaController::class, 'estado']);
    Route::get('documentos',   [FacturaElectronicaController::class, 'documentos']);
    Route::get('por-emitir',   [FacturaElectronicaController::class, 'porEmitir']);
    Route::get('cliente-por-defecto', [FacturaElectronicaController::class, 'clientePorDefecto']);
    Route::get('cliente/{userId}', [FacturaElectronicaController::class, 'clienteFiscal'])->whereNumber('userId');
    Route::put('cliente/{userId}', [FacturaElectronicaController::class, 'guardarClienteFiscal'])->whereNumber('userId')->middleware('module:usuario');
    Route::get('documentos/{id}/pdf', [FacturaElectronicaController::class, 'pdf'])->whereNumber('id');

    // Cada emisión espera a la DIAN: se limita para no agotar los procesos de PHP.
    Route::post('emitir',                       [FacturaElectronicaController::class, 'emitir'])->middleware('throttle:12,1');
    Route::post('documentos/{id}/reintentar',   [FacturaElectronicaController::class, 'reintentar'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('documentos/{id}/nota-credito', [FacturaElectronicaController::class, 'notaCredito'])->whereNumber('id')->middleware('throttle:12,1');

    Route::middleware('role:admin')->group(function () {
        Route::put('/',          [FacturaElectronicaController::class, 'guardar']);
        Route::post('probar',    [FacturaElectronicaController::class, 'probar'])->middleware('throttle:10,1');
        Route::get('catalogos',  [FacturaElectronicaController::class, 'catalogos']);
    });
});
