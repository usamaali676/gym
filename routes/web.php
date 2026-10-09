<?php

use App\Http\Controllers\AccountClaimController;
use App\Http\Controllers\DigestUnsubscribeController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicReportController;
use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');

Route::get('/r/{token}', [PublicReportController::class, 'show'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9]{32}')->name('reports.public');

Route::middleware(['signed', 'throttle:20,1'])->group(function () {
    Route::get('/digest/unsubscribe/{user}', [DigestUnsubscribeController::class, 'confirm'])->whereNumber('user')->name('digest.unsubscribe');
    Route::post('/digest/unsubscribe/{user}', [DigestUnsubscribeController::class, 'unsubscribe'])->whereNumber('user');
});

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
