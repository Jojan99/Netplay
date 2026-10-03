<?php

use App\Http\Controllers\AlegraController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo de Alegra: lo que la empresa tiene allá, cruzado con Netvula
|--------------------------------------------------------------------------
| Consulta, más la bandeja de aprobación (lo único que escribe en Alegra). El controlador
| sólo deja entrar al administrador y al contador.
*/
Route::prefix('alegra')->group(function () {
    Route::get('/',             [AlegraController::class, 'estado']);
    Route::get('resumen',       [AlegraController::class, 'resumen']);
    Route::get('facturas',      [AlegraController::class, 'listarFacturas']);
    Route::get('por-facturar',  [AlegraController::class, 'porFacturar']);
    Route::get('recurrentes',   [AlegraController::class, 'recurrentes']);
    Route::get('pagos',         [AlegraController::class, 'pagos']);
    Route::get('contactos',     [AlegraController::class, 'contactos']);
    Route::get('cuenta',        [AlegraController::class, 'cuenta'])->middleware('throttle:20,1');
    // La bandeja: lo único que termina escribiendo en Alegra, y sólo lo aprobado.
    Route::get('operaciones',             [AlegraController::class, 'operaciones']);
    Route::post('operaciones/proponer',   [AlegraController::class, 'proponer'])->middleware('throttle:10,1');
    Route::post('operaciones/aprobar',    [AlegraController::class, 'aprobar'])->middleware('throttle:30,1');
    Route::post('operaciones/descartar',  [AlegraController::class, 'descartar']);
    Route::post('operaciones/restaurar',  [AlegraController::class, 'restaurar']);
    Route::post('operaciones/verificar',  [AlegraController::class, 'verificar']);
    Route::put('ajustes',                 [AlegraController::class, 'ajustes']);
    Route::post('contactos/crear',        [AlegraController::class, 'crearContacto']);
    Route::post('recurrentes/quitar',     [AlegraController::class, 'quitarRecurrente']);
    Route::post('recurrentes/agregar',    [AlegraController::class, 'agregarRecurrente']);

    // Son unas 65 consultas a Alegra: de a una corrida, y sin poder pedirla en ráfaga.
    Route::post('sincronizar',  [AlegraController::class, 'sincronizar'])->middleware('throttle:6,1');
});
