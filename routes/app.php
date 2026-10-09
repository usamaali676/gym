<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| Authenticated, onboarded application routes (loaded inside web.php).
| Each phase adds its own section below.
*/

Route::get('/dashboard', DashboardController::class)->name('dashboard');
