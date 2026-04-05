<?php

use App\Http\Controllers\SignInController;
use Illuminate\Support\Facades\Route;

Route::prefix('oauth')->group(function () {
    Route::post('signin', [SignInController::class, 'signin'])->withoutMiddleware('jwt.verify');
    Route::post('logout', [SignInController::class, 'logout']);
    Route::post('changePassword', [SignInController::class, 'changePassword']);
    Route::get('me', [SignInController::class, 'getMe']);
    Route::put('profile', [SignInController::class, 'updateProfile']);
});
