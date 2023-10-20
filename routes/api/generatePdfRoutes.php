<?php

use App\Http\Controllers\GeneratePdfController;
use Illuminate\Support\Facades\Route;


Route::prefix('generatePdf')->group(function () {
    Route::post('generatePdf', [GeneratePdfController::class, 'generatePdf'])->withoutMiddleware('jwt.verify');
    Route::get('generatePdfbyId/{userid}', [GeneratePdfController::class, 'generatePdfbyId'])->withoutMiddleware('jwt.verify');
});