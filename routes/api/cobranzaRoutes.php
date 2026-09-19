<?php

use App\Http\Controllers\CobranzaController;
use Illuminate\Support\Facades\Route;

// Cobranza inteligente: configuración, casos y la burbuja del panel.
Route::prefix('cobranza')->middleware('role:admin,contador')->group(function () {
    Route::get('config',  [CobranzaController::class, 'config']);
    Route::put('config',  [CobranzaController::class, 'guardarConfig']);
    Route::get('resumen', [CobranzaController::class, 'resumen']);
    Route::post('vistos', [CobranzaController::class, 'marcarVistos']);
    Route::post('revisar', [CobranzaController::class, 'revisarAhora']);
    Route::post('ia/probar', [CobranzaController::class, 'probarIa']);
    Route::get('casos',   [CobranzaController::class, 'casos']);
    Route::get('casos/{id}', [CobranzaController::class, 'caso'])->whereNumber('id');
    Route::post('casos/{id}/autorizar', [CobranzaController::class, 'autorizar'])->whereNumber('id');
    Route::post('casos/{id}/descartar', [CobranzaController::class, 'descartar'])->whereNumber('id');
    Route::post('casos/{id}/tomar',     [CobranzaController::class, 'tomar'])->whereNumber('id');
    Route::post('casos/{id}/devolver',  [CobranzaController::class, 'devolver'])->whereNumber('id');
});
