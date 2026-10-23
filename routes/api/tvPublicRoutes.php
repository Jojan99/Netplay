<?php

use App\Http\Controllers\TelevisionController;
use Illuminate\Support\Facades\Route;

// Previas y señal en vivo: el <img> y el reproductor no mandan la sesión, así que van con enlaces
// firmados que vencen solos (los firma GET tv/canales, que sí exige el módulo).
Route::prefix('tv')->middleware('signed:relative')->group(function () {
    Route::get('previa/{empresa}/{stream}', [TelevisionController::class, 'previa'])->whereNumber(['empresa', 'stream'])->name('tv.previa');
    Route::get('vivo/{empresa}/{stream}', [TelevisionController::class, 'vivo'])->whereNumber(['empresa', 'stream'])->name('tv.vivo');
    Route::get('trozo/{empresa}', [TelevisionController::class, 'trozo'])->whereNumber('empresa')->name('tv.trozo');
});
