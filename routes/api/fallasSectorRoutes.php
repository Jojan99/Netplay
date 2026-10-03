<?php

use App\Http\Controllers\FallasSectorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fallas de sector
|--------------------------------------------------------------------------
| Lo ve y lo atiende (aprobar, descartar, cerrar) quien tenga el módulo de la OLT. La
| configuración y los nombres de los sectores son del administrador.
*/
Route::prefix('fallas-sector')->middleware(['empresa.propia', 'module:olt-admin'])->group(function () {
    Route::get('/',                         [FallasSectorController::class, 'index']);
    Route::put('config',                    [FallasSectorController::class, 'guardar'])->middleware('role:admin');
    Route::put('sectores',                  [FallasSectorController::class, 'sectores'])->middleware('role:admin');
    Route::post('probar',                   [FallasSectorController::class, 'probar'])->middleware(['role:admin', 'throttle:6,1']);
    Route::post('revisar',                  [FallasSectorController::class, 'revisar'])->middleware('throttle:6,1');
    Route::get('fallas/{id}',               [FallasSectorController::class, 'falla'])->whereNumber('id');
    Route::post('fallas/{id}/{accion}',     [FallasSectorController::class, 'accion'])->whereNumber('id')->whereIn('accion', ['aprobar', 'descartar', 'resolver']);
});
