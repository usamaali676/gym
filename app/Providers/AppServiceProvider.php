<?php

namespace App\Providers;

use App\View\Composers\NavComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch N+1 queries early (development and tests only).
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        $key = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($key($request)));
        RateLimiter::for('coach', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        RateLimiter::for('guest', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        View::composer('components.layouts.app', NavComposer::class);
    }
}
