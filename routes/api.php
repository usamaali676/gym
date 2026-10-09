<?php

use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\WorkoutSessionController;
use Illuminate\Support\Facades\Route;

/*
| Same-origin JSON API (session cookie + CSRF header, rate limited, onboarded users only).
| Mounted at /api with names prefixed "api." (see bootstrap/app.php).
*/

Route::get('/plan-items/{item}/alternatives', [SwapController::class, 'index'])->name('swap.alternatives');
Route::post('/plan-items/{item}/swap', [SwapController::class, 'store'])->name('swap.store');
Route::delete('/plan-items/{item}/swap', [SwapController::class, 'destroy'])->name('swap.destroy');

// Running workout (game mode)
Route::prefix('workout-sessions/{session}')->whereNumber('session')->name('workout.')->group(function () {
    Route::put('/state', [WorkoutSessionController::class, 'state'])->name('state');
    Route::post('/sets', [WorkoutSessionController::class, 'logSet'])->name('sets');
    Route::delete('/sets/{set}', [WorkoutSessionController::class, 'deleteSet'])->whereNumber('set')->name('sets.destroy');
    Route::get('/exercises/{exercise}/alternatives', [WorkoutSessionController::class, 'alternatives'])->whereNumber('exercise')->name('alternatives');
    Route::post('/exercises/{exercise}/swap', [WorkoutSessionController::class, 'swap'])->whereNumber('exercise')->name('swap');
    Route::post('/exercises/{exercise}/skip', [WorkoutSessionController::class, 'skipExercise'])->whereNumber('exercise')->name('skip');
    Route::post('/finish', [WorkoutSessionController::class, 'finish'])->name('finish');
    Route::post('/abandon', [WorkoutSessionController::class, 'abandon'])->name('abandon');
});
