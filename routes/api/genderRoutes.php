<?php

use App\Http\Controllers\GenderController;
use Illuminate\Support\Facades\Route;


Route::prefix('gender')->group(function () {
    Route::get('getGenderAll', [GenderController::class, 'getGenderAll'])->withoutMiddleware('jwt.verify');
});