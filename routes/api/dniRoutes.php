<?php

use App\Http\Controllers\DniController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\OltController;
use Illuminate\Support\Facades\Route;


Route::prefix('dni')->group(function () {
    Route::get('getDniAll', [DniController::class, 'getDniAll']);
    Route::get('pruebaMikro', [DniController::class, 'pruebaMikro']);
    Route::get('pruebaMikroPing', [DniController::class, 'pruebaMikroPing'])->withoutMiddleware('jwt.verify');
    Route::get('pruebaMikroPingBots', [DniController::class, 'pruebaMikroPingBots'])->withoutMiddleware('jwt.verify');
    Route::get('diagnosticoConexionBot', [DniController::class, 'diagnosticoConexionBot'])->withoutMiddleware('jwt.verify');
    Route::get('pruebaMikroAll', [DniController::class, 'pruebaMikroAll']);
    Route::get('listFiles', [FileController::class, 'listFiles']);
    Route::get('downloadFiles/{name}', [FileController::class, 'downloadFiles']);
    Route::get('createUser', [DniController::class, 'createUser']);
    Route::post('disableUser', [DniController::class, 'disableUser']);
    Route::get('getOltName', [OltController::class, 'getOltName'])->withoutMiddleware('jwt.verify');
});