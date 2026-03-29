<?php

use App\Http\Controllers\InternetInfoController;
use Illuminate\Support\Facades\Route;


Route::prefix('internetInfo')->group(function () {
    Route::get('getInternetPlanAll', [InternetInfoController::class, 'getInternetPlanAll']);
    Route::get('getDataCorteAll', [InternetInfoController::class, 'getDataCorteAll']);
    Route::post('getIpAllByIdZone', [InternetInfoController::class, 'getIpAllByIdZone']);

    
});