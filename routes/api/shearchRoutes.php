<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;


Route::prefix('shearch')->group(function () {
    Route::post('getSearchUseCase', [SearchController::class, 'getSearchUseCase']);
    Route::post('SearchFinancesPaid', [SearchController::class, 'SearchFinancesPaid']);
});