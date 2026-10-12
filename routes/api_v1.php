<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\ExerciseController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\PublicContentController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API, version 1. Mounted at /api/v1 with names prefixed "v1." (see bootstrap/app.php).
| Bearer-token (Sanctum) authentication only; no session, no CSRF. Every response is JSON.
| Keep docs/mobile/openapi.yaml in step: tests/Feature/Api/V1/OpenApiParityTest.php fails when they disagree.
*/

// Public
Route::middleware('throttle:v1-public')->group(function () {
    Route::get('/app/config', AppConfigController::class)->name('app.config');
    Route::get('/membership/plans', [MembershipController::class, 'plans'])->name('membership.plans');

    // Media behind signed, expiring URLs (issued only to members allowed to see the item)
    Route::middleware('signed')->prefix('media')->name('media.')->group(function () {
        Route::get('/exercise/{media}', [MediaController::class, 'exercise'])->whereNumber('media')->name('exercise');
        Route::get('/banner/{banner}', [MediaController::class, 'banner'])->whereNumber('banner')->name('banner');
    });
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

    Route::prefix('membership')->name('membership.')->group(function () {
        Route::get('/', [MembershipController::class, 'show'])->name('show');
        Route::get('/entitlements', [MembershipController::class, 'entitlements'])->name('entitlements');
        Route::post('/purchases/verify', [MembershipController::class, 'verify'])->middleware('throttle:v1-purchases')->name('verify');
        Route::post('/restore', [MembershipController::class, 'restore'])->middleware('throttle:v1-purchases')->name('restore');
    });

    // Content: browse, cache and sync
    Route::prefix('content')->name('content.')->group(function () {
        Route::get('/manifest', [ContentController::class, 'manifest'])->name('manifest');
        Route::get('/changes', [ContentController::class, 'changes'])->name('changes');
        Route::get('/{collection}', [ContentController::class, 'snapshot'])->where('collection', '[a-z_]+')->name('snapshot');
    });
    Route::get('/programs', [ProgramController::class, 'index'])->name('programs.index');
    Route::get('/programs/{plan}', [ProgramController::class, 'show'])->whereNumber('plan')->name('programs.show');
    Route::get('/exercises', [ExerciseController::class, 'index'])->name('exercises.index');
    Route::get('/exercises/{exercise}', [ExerciseController::class, 'show'])->where('exercise', '[a-z0-9-]+')->name('exercises.show');
    Route::get('/announcements', [PublicContentController::class, 'announcements'])->name('announcements');
    Route::get('/banners', [PublicContentController::class, 'banners'])->name('banners');
    Route::get('/help', [PublicContentController::class, 'help'])->name('help.index');
    Route::get('/help/{slug}', [PublicContentController::class, 'helpArticle'])->where('slug', '[a-z0-9-]+')->name('help.show');

    // Everything below needs a finished profile.
    Route::middleware('api.onboarded')->group(function () {
        Route::put('/me/profile', [MeController::class, 'updateProfile'])->name('me.profile.update');
        Route::get('/me/settings', [MeController::class, 'settings'])->name('me.settings');
        Route::put('/me/settings', [MeController::class, 'updateSettings'])->name('me.settings.update');
        Route::post('/me/program', [ProgramController::class, 'select'])->name('me.program');
        Route::get('/exercises/{exercise}/alternatives', [ExerciseController::class, 'alternatives'])->where('exercise', '[a-z0-9-]+')->name('exercises.alternatives');
    });
});
