<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->middleware('role:admin,contador')->group(function () {

    // Categorías
    Route::get('categories',        [InventoryController::class, 'getCategories']);
    Route::post('categories',       [InventoryController::class, 'createCategory']);

    // Ítems
    Route::get('items',             [InventoryController::class, 'getAll']);
    Route::get('items/{id}',        [InventoryController::class, 'getById']);
    Route::post('items',            [InventoryController::class, 'create']);
    Route::put('items/{id}',        [InventoryController::class, 'update']);
    Route::delete('items/{id}',     [InventoryController::class, 'delete']);

    // Movimientos
    Route::post('movements',              [InventoryController::class, 'createMovement']);
    Route::get('movements',               [InventoryController::class, 'getAllMovements']);
    Route::get('movements/{inventoryId}', [InventoryController::class, 'getMovements']);
});
