<?php

use App\Http\Controllers\TelevisionController;
use Illuminate\Support\Facades\Route;

/*
| Televisión por IPTV (panel XUI.one). El módulo «television» ve canales, líneas y configuración;
| la tarjeta de TV de la ficha la usa también quien atiende clientes (módulo «usuario»).
*/
Route::prefix('tv')->middleware('module:television')->group(function () {
    Route::get('configuracion', [TelevisionController::class, 'configuracion']);
    Route::put('configuracion', [TelevisionController::class, 'guardarConfiguracion']);
    Route::put('planes/{id}', [TelevisionController::class, 'guardarPlan'])->whereNumber('id');

    Route::get('canales', [TelevisionController::class, 'canales']);
    Route::post('canales/revisar', [TelevisionController::class, 'revisarCanales'])->middleware('throttle:6,1');
    Route::patch('canales/{id}', [TelevisionController::class, 'vigilarCanal'])->whereNumber('id');

    Route::get('lineas', [TelevisionController::class, 'listaDeLineas']);
    Route::post('lineas/sincronizar', [TelevisionController::class, 'sincronizarLineas'])->middleware('throttle:4,1');
    Route::patch('lineas/{id}/cliente', [TelevisionController::class, 'vincular'])->whereNumber('id');
    Route::get('clientes', [TelevisionController::class, 'buscarClientes']);
});

Route::prefix('tv')->middleware('module:usuario,television')->group(function () {
    Route::get('cliente/{userId}', [TelevisionController::class, 'deCliente'])->whereNumber('userId');
    Route::post('cliente/{userId}/linea', [TelevisionController::class, 'crearDeCliente'])->whereNumber('userId');
    Route::post('lineas/{id}/clave', [TelevisionController::class, 'cambiarClave'])->whereNumber('id');
    Route::post('lineas/{id}/encender', [TelevisionController::class, 'encender'])->whereNumber('id');
    Route::post('lineas/{id}/paquete', [TelevisionController::class, 'paquete'])->whereNumber('id');
    Route::post('lineas/{id}/whatsapp', [TelevisionController::class, 'whatsapp'])->whereNumber('id')->middleware('throttle:10,1');
});
