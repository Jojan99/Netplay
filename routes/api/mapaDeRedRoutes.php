<?php

use App\Http\Controllers\MapaDeRedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mapa de la red
|--------------------------------------------------------------------------
| Lo ve quien tenga el módulo de la OLT; poner, mover y borrar OLT, cajas y
| clientes es del administrador.
*/
Route::prefix('mapa-red')->middleware(['empresa.propia', 'module:olt-admin'])->group(function () {
    Route::get('/', [MapaDeRedController::class, 'index']);

    Route::middleware('role:admin')->group(function () {
        Route::post('puntos',                   [MapaDeRedController::class, 'crearPunto']);
        Route::put('puntos/{id}',               [MapaDeRedController::class, 'editarPunto'])->whereNumber('id');
        Route::delete('puntos/{id}',            [MapaDeRedController::class, 'borrarPunto'])->whereNumber('id');
        Route::put('clientes/{id}',             [MapaDeRedController::class, 'ubicarCliente'])->whereNumber('id');
        Route::delete('clientes/{id}',          [MapaDeRedController::class, 'quitarCliente'])->whereNumber('id');
    });
});
