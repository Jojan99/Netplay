<?php

use App\Http\Controllers\SoporteAsistenteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Asistente de soporte por WhatsApp
|--------------------------------------------------------------------------
| Los casos los ve quien tenga el módulo «crm». Encenderlo o cambiar lo que puede hacer
| (clave del WiFi, reinicios, tickets) es sólo del administrador.
*/
Route::prefix('soporte-asistente')->middleware('module:crm')->group(function () {
    Route::get('/',                   [SoporteAsistenteController::class, 'estado']);
    Route::put('config',              [SoporteAsistenteController::class, 'guardar'])->middleware('role:admin');
    Route::get('casos',               [SoporteAsistenteController::class, 'casos']);
    Route::get('casos/{id}',          [SoporteAsistenteController::class, 'caso'])->whereNumber('id');
    Route::post('casos/{id}/tomar',   [SoporteAsistenteController::class, 'tomar'])->whereNumber('id');
    Route::post('probar',             [SoporteAsistenteController::class, 'probar'])->middleware('throttle:10,1');
});
