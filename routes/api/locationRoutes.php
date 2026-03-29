<?php

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;


Route::prefix('location')->group(function () {
    Route::get('getneighborhoodAll', [LocationController::class, 'getneighborhoodAll']);
});