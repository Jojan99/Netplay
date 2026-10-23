<?php

use App\Http\Controllers\PagosController;
use Illuminate\Support\Facades\Route;

// Finanzas › Pagos: lo registrado, los pagos en línea sin terminar y el cuadre de caja.
Route::prefix('pagos')->middleware('role:admin,contador')->group(function () {
    Route::get('registrados', [PagosController::class, 'registrados']);
    Route::get('en-linea', [PagosController::class, 'enLinea']);
    Route::post('en-linea/{id}/escribirle', [PagosController::class, 'escribirle'])->whereNumber('id');
    Route::get('caja', [PagosController::class, 'caja']);
    Route::post('caja', [PagosController::class, 'cerrarCaja']);
});
