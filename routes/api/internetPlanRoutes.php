<?php

use App\Http\Controllers\InternetPlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('plans')->middleware(['jwt.verify'])->group(function () {
    Route::get('/',           [InternetPlanController::class, 'index']);
    Route::post('/',          [InternetPlanController::class, 'store']);
    Route::put('/{id}',       [InternetPlanController::class, 'update']);
    Route::delete('/{id}',    [InternetPlanController::class, 'destroy']);
    Route::patch('/{id}/toggle', [InternetPlanController::class, 'toggle']);
});
