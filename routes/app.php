<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlanController;
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
