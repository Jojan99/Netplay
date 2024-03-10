<?php

use App\Http\Controllers\FacturationController;
use Illuminate\Support\Facades\Route;

Route::prefix('facturation')->group(function () {
    Route::post('createDetFacturation', [FacturationController::class, 'createDetFacturation'])->withoutMiddleware('jwt.verify')    ;
    Route::get('getDateFacturePending', [FacturationController::class, 'getDateFacturePending'])->withoutMiddleware('jwt.verify');
    Route::get('getDataInfoPenddingFacture', [FacturationController::class, 'getDataInfoPenddingFacture'])->withoutMiddleware('jwt.verify');
});
