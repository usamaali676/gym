<?php

use App\Http\Controllers\Admin\DemoGalleryController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DietController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PostureLabController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\RecoveryController;
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

// Diet and food log
Route::get('/diet', [DietController::class, 'index'])->name('diet.index');
Route::post('/diet/recalculate', [DietController::class, 'recalculate'])->name('diet.recalculate');
Route::get('/food', [FoodController::class, 'index'])->name('food.index');

// Progress: body measurements, photos, strength
Route::get('/progress', [ProgressController::class, 'index'])->name('progress.index');
Route::post('/progress/measurements', [ProgressController::class, 'storeMeasurement'])->middleware('throttle:30,1')->name('progress.measurements.store');
Route::delete('/progress/measurements/{measurement}', [ProgressController::class, 'destroyMeasurement'])->whereNumber('measurement')->name('progress.measurements.destroy');
Route::post('/progress/photos', [ProgressController::class, 'storePhoto'])->middleware('throttle:uploads')->name('progress.photos.store');
Route::get('/progress/photos/{photo}', [ProgressController::class, 'showPhoto'])->whereNumber('photo')->name('progress.photos.show');
Route::delete('/progress/photos/{photo}', [ProgressController::class, 'destroyPhoto'])->whereNumber('photo')->name('progress.photos.destroy');

// Recovery: habits, warm-up / cool-down generator
Route::get('/recovery', [RecoveryController::class, 'index'])->name('recovery.index');

// Badges and leaderboard
Route::get('/badges', [BadgeController::class, 'index'])->name('badges.index');

// Posture Lab
Route::get('/posture', [PostureLabController::class, 'index'])->name('posture.index');
Route::get('/posture/{exercise}', [PostureLabController::class, 'show'])->name('posture.show');

// Admin tools (the full admin panel arrives in a later phase)
Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/demos', DemoGalleryController::class)->name('demos');
});
