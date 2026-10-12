<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API, version 1. Mounted at /api/v1 with names prefixed "v1." (see bootstrap/app.php).
| Bearer-token (Sanctum) authentication only; no session, no CSRF. Every response is JSON.
| Keep docs/mobile/openapi.yaml in step: tests/Feature/Api/V1/OpenApiParityTest.php fails when they disagree.
*/

// Public
Route::middleware('throttle:v1-public')->group(function () {
    Route::get('/app/config', AppConfigController::class)->name('app.config');
});

Route::middleware('throttle:v1-auth')->prefix('auth')->name('auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/guest', [AuthController::class, 'guest'])->name('guest');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
});

// Signed in (may still need onboarding)
Route::middleware(['auth:sanctum', 'api.active', 'api.touch', 'throttle:v1'])->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::get('/sessions', [AuthController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{device}', [AuthController::class, 'revokeSession'])->whereNumber('device')->name('sessions.destroy');
    });

    Route::prefix('me')->name('me.')->group(function () {
        Route::get('/', [MeController::class, 'show'])->name('show');
        Route::patch('/', [MeController::class, 'update'])->name('update');
        Route::put('/password', [MeController::class, 'updatePassword'])->name('password');
        Route::delete('/', [MeController::class, 'destroy'])->name('destroy');
        Route::post('/claim', [MeController::class, 'claim'])->name('claim');
        Route::post('/onboarding', [MeController::class, 'onboarding'])->name('onboarding');
        Route::get('/profile', [MeController::class, 'profile'])->name('profile');
    });

    // Everything below needs a finished profile.
    Route::middleware('api.onboarded')->group(function () {
        Route::put('/me/profile', [MeController::class, 'updateProfile'])->name('me.profile.update');
        Route::get('/me/settings', [MeController::class, 'settings'])->name('me.settings');
        Route::put('/me/settings', [MeController::class, 'updateSettings'])->name('me.settings.update');
    });
});
