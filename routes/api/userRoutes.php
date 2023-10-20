<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('user')->group(function () {
    Route::post('createUserData', [UserController::class, 'createUserData'])->withoutMiddleware('jwt.verify');
    Route::get('getUserLoggedIn', [UserController::class, 'getUserLoggedIn']);
    Route::post('updateUserData', [UserController::class, 'updateUserData'])->withoutMiddleware('jwt.verify');
    Route::get('generatePdf', [UserController::class, 'generatePdf'])->withoutMiddleware('jwt.verify');
    Route::get('getUserAll', [UserController::class, 'getUserAll'])->withoutMiddleware('jwt.verify');
    Route::get('getUserById/{id}', [UserController::class, 'getUserById']);
    Route::delete('deleteUserDataById/{id}', [UserController::class, 'DeleteUserData'])->withoutMiddleware('jwt.verify');
});