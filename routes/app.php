<?php

use App\Http\Controllers\Admin\DemoGalleryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\WorkoutController;
use Illuminate\Support\Facades\Route;

/*
| Authenticated, onboarded application routes (loaded inside web.php).
| Each phase adds its own section below.
*/

Route::get('/dashboard', DashboardController::class)->name('dashboard');

// Plan
Route::get('/plan', [PlanController::class, 'index'])->name('plan.index');
Route::get('/plan/{day}', [PlanController::class, 'show'])->whereNumber('day')->name('plan.day');
Route::post('/plan/{day}/complete', [PlanController::class, 'complete'])->whereNumber('day')->name('plan.complete');
Route::post('/plan/{day}/skip', [PlanController::class, 'skip'])->whereNumber('day')->name('plan.skip');
Route::delete('/plan/{day}/skip', [PlanController::class, 'unskip'])->whereNumber('day')->name('plan.unskip');

// Exercise library
Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
Route::get('/library/{exercise}', [LibraryController::class, 'show'])->name('library.show');

// Workout (game mode)
Route::get('/workout', [WorkoutController::class, 'start'])->name('workout.start');
Route::get('/workout/{session}', [WorkoutController::class, 'play'])->whereNumber('session')->name('workout.play');
Route::get('/workout/{session}/summary', [WorkoutController::class, 'summary'])->whereNumber('session')->name('workout.summary');

// Admin tools (the full admin panel arrives in a later phase)
Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/demos', DemoGalleryController::class)->name('demos');
});
