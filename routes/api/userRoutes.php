<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('user')->group(function () {
    Route::post('createUserData', [UserController::class, 'createUserData'])->withoutMiddleware('jwt.verify');
    Route::get('getUserLoggedIn', [UserController::class, 'getUserLoggedIn']);
    Route::post('UpdateUserData', [UserController::class, 'UpdateUserData'])->withoutMiddleware('jwt.verify');
    Route::get('generatePdf', [UserController::class, 'generatePdf'])->withoutMiddleware('jwt.verify');
    Route::get('getUserAll', [UserController::class, 'getUserAll']);
    Route::get('getUserById/{id}', [UserController::class, 'getUserById']);
});