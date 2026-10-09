<?php

use App\Http\Controllers\Api\SwapController;
use Illuminate\Support\Facades\Route;

/*
| Same-origin JSON API (session cookie + CSRF header, rate limited, onboarded users only).
| Mounted at /api with names prefixed "api." (see bootstrap/app.php).
*/

Route::get('/plan-items/{item}/alternatives', [SwapController::class, 'index'])->name('swap.alternatives');
Route::post('/plan-items/{item}/swap', [SwapController::class, 'store'])->name('swap.store');
Route::delete('/plan-items/{item}/swap', [SwapController::class, 'destroy'])->name('swap.destroy');
