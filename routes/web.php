<?php

use App\Http\Controllers\AccountClaimController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::post('/guest', [GuestController::class, 'store'])
    ->middleware(['guest', 'throttle:guest'])
    ->name('guest.start');

Route::middleware('auth')->group(function () {
    // Reachable before onboarding is finished.
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('onboarded')->group(function () {
        Route::get('/account/claim', [AccountClaimController::class, 'show'])->name('account.claim');
        Route::post('/account/claim', [AccountClaimController::class, 'store'])->name('account.claim.store');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/body', [ProfileController::class, 'body'])->name('profile.body');
        Route::patch('/profile/settings', [ProfileController::class, 'settings'])->name('profile.settings');

        require __DIR__.'/app.php';
    });
});

require __DIR__.'/auth.php';
