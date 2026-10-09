<?php

use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\FoodLogController;
use App\Http\Controllers\Api\PostureSessionController;
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

// Posture Lab: store the numeric summary of a set (nothing from the camera ever leaves the device)
Route::post('/posture-sessions', [PostureSessionController::class, 'store'])->name('posture.store');

// Food log: search, custom foods and the day's entries
Route::get('/foods/search', [FoodController::class, 'search'])->name('foods.search');
Route::get('/foods/recent', [FoodController::class, 'recent'])->name('foods.recent');
Route::post('/foods', [FoodController::class, 'store'])->name('foods.store');
Route::get('/foods/{food}', [FoodController::class, 'show'])->whereNumber('food')->name('foods.show');
Route::delete('/foods/{food}', [FoodController::class, 'destroy'])->whereNumber('food')->name('foods.destroy');
Route::post('/food-logs', [FoodLogController::class, 'store'])->name('food-logs.store');
Route::post('/food-logs/batch', [FoodLogController::class, 'batch'])->name('food-logs.batch');
Route::post('/food-logs/copy', [FoodLogController::class, 'copy'])->name('food-logs.copy');
Route::patch('/food-logs/{log}', [FoodLogController::class, 'update'])->whereNumber('log')->name('food-logs.update');
Route::delete('/food-logs/{log}', [FoodLogController::class, 'destroy'])->whereNumber('log')->name('food-logs.destroy');
