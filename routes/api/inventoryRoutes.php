<?php

use App\Http\Controllers\InventarioController;
use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventario
|--------------------------------------------------------------------------
| Entra quien tenga el módulo «inventory» en su perfil. Antes pedía ser administrador o
| contador por nombre de perfil, y un perfil propio con el módulo activo veía la pantalla
| y recibía 403 en todas las consultas.
*/
Route::prefix('inventory')->middleware('module:inventory')->group(function () {

    // Categorías
    Route::get('categories',         [InventoryController::class, 'getCategories']);
    Route::post('categories',        [InventoryController::class, 'createCategory']);
    Route::put('categories/{id}',    [InventoryController::class, 'updateCategory'])->whereNumber('id');
    Route::delete('categories/{id}', [InventoryController::class, 'deleteCategory'])->whereNumber('id');

    // Ítems
    Route::get('items',              [InventoryController::class, 'getAll']);
    Route::get('items/{id}',         [InventoryController::class, 'getById'])->whereNumber('id');
    Route::post('items',             [InventoryController::class, 'create']);
    Route::put('items/{id}',         [InventoryController::class, 'update'])->whereNumber('id');
    Route::delete('items/{id}',      [InventoryController::class, 'delete'])->whereNumber('id');

    // Logística / utilidades
    Route::get('low-stock',          [InventoryController::class, 'lowStock']);
    Route::get('locations',          [InventoryController::class, 'locations']);

    // Movimientos (historial y el registro clásico)
    Route::post('movements',              [InventoryController::class, 'createMovement']);
    Route::get('movements',               [InventoryController::class, 'getAllMovements']);
    Route::get('movements/{inventoryId}', [InventoryController::class, 'getMovements'])->whereNumber('inventoryId');

    // Tablero, lector y técnicos
    Route::get('panel',           [InventarioController::class, 'panel']);
    Route::get('buscar',          [InventarioController::class, 'buscar']);
    Route::get('tecnicos',        [InventarioController::class, 'tecnicos']);
    Route::get('unidades',        [InventarioController::class, 'unidades']);
    Route::post('entradas',       [InventarioController::class, 'entrada']);
    Route::post('salidas',        [InventarioController::class, 'salida']);
    Route::post('ajustes',        [InventarioController::class, 'ajuste']);
    Route::post('entregas',       [InventarioController::class, 'entregar']);
    Route::post('devoluciones',   [InventarioController::class, 'devolver']);
    Route::post('consumos',       [InventarioController::class, 'consumir']);
    // Cada pregunta llama al modelo: se limita para no agotar la cuota ni los procesos.
    Route::post('asistente',      [InventarioController::class, 'asistente'])->middleware('throttle:20,1');
});

// Lo que tiene a su nombre quien está en sesión: lo ve cualquier técnico, tenga o no el módulo.
Route::get('inventory/mi-material', [InventarioController::class, 'miMaterial']);
