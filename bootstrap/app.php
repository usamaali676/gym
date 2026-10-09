<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The small JSON API (workout/posture autosave, food search, water) is same-origin
            // and session-authenticated, so it lives in the web group (cookies + CSRF) and is rate limited.
            Route::middleware(['web', 'auth', 'onboarded', 'throttle:api'])
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'onboarded' => EnsureOnboarded::class,
            'admin' => EnsureAdmin::class,
        ]);
        // Mail apps POST one-click unsubscribes with no session or CSRF token; the signed URL authenticates it.
        $middleware->validateCsrfTokens(except: ['digest/unsubscribe/*']);
        $middleware->web(append: [SecurityHeaders::class, TouchLastSeen::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
