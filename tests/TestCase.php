<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private string $defaultGuard = 'web';

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered in tests without needing `npm run build` first.
        $this->withoutVite();

        $this->defaultGuard = config('auth.defaults.guard');
    }

    /**
     * Every request starts on the configured default guard, as it would in a fresh PHP process. Without this, a
     * request through `auth:sanctum` (which switches the default guard) would leak into the next request of the
     * same test and make `actingAs()` / `auth()->logout()` talk to the wrong guard.
     * (The default guard *is* the `auth.defaults.guard` config value, so the original is remembered in setUp.)
     */
    private function resetDefaultGuard(): void
    {
        $this->app['auth']->shouldUse($this->defaultGuard);
    }

    public function be(Authenticatable $user, $guard = null)
    {
        $this->resetDefaultGuard();

        return parent::be($user, $guard);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->resetDefaultGuard();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
