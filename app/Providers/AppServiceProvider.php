<?php

namespace App\Providers;

use App\Services\Coach\AnthropicCoachModel;
use App\Services\Coach\CoachModel;
use App\Services\DietCalculatorService;
use App\Services\Push\FcmPushProvider;
use App\Services\Push\NullPushProvider;
use App\Services\Push\PushProvider;
use App\View\Composers\NavComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DietCalculatorService::class, fn () => new DietCalculatorService(
            (float) config('ironpulse.water.ml_per_kg'),
            (int) config('ironpulse.water.training_day_bonus_ml'),
        ));

        // Swappable in tests; in the app it is the official Anthropic SDK, called from the server only.
        $this->app->bind(CoachModel::class, AnthropicCoachModel::class);

        // Push goes nowhere until a Firebase project is configured (see config/ironpulse.php, 'push').
        $this->app->bind(PushProvider::class, fn () => config('ironpulse.push.enabled') ? new FcmPushProvider : new NullPushProvider);
    }

    public function boot(): void
    {
        // Catch N+1 queries early (development and tests only).
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Paginator::defaultView('vendor.pagination.neon');

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        $key = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($key($request)));
        RateLimiter::for('coach', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        RateLimiter::for('guest', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Mobile API (/api/v1)
        RateLimiter::for('v1-public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('v1-auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('v1', fn (Request $request) => Limit::perMinute(240)->by($key($request)));
        RateLimiter::for('v1-sync', fn (Request $request) => Limit::perMinute(60)->by($key($request)));
        RateLimiter::for('v1-purchases', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('v1-uploads', fn (Request $request) => Limit::perMinute(20)->by($key($request)));

        View::composer('components.layouts.app', NavComposer::class);
    }
}
