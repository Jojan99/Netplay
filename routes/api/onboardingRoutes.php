<?php

use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

/**
 * Guía de primeros pasos. Va con la sesión normal del panel: cada usuario ve
 * el avance de su propia empresa y decide si la sigue mostrando.
 */
Route::prefix('onboarding')->group(function () {
    Route::get('/',     [OnboardingController::class, 'index']);
    Route::post('done', [OnboardingController::class, 'toggle']);
});
