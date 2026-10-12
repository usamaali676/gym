<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\ApiException;
use App\Models\AppVersionPolicy;
use App\Services\App\AppVersionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks builds older than the minimum supported version (426) and every request during maintenance (503).
 * `GET /app/config` is exempt so a blocked app can still learn why and show the update / maintenance screen.
 *
 * Apps send X-App-Platform (android|ios) and X-App-Version. Without them the policy cannot be applied.
 */
class EnforceAppVersion
{
    public function __construct(private readonly AppVersionService $versions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('v1.app.config')) {
            return $next($request);
        }

        $platform = strtolower((string) $request->header('X-App-Platform'));
        $policy = in_array($platform, AppVersionPolicy::PLATFORMS, true) ? AppVersionPolicy::forPlatform($platform) : null;

        if ($policy === null) {
            return $next($request);
        }

        $maintenance = $this->versions->maintenance($policy);
        if ($maintenance['enabled']) {
            $retry = $policy->maintenance_ends_at ? max(60, (int) now()->diffInSeconds($policy->maintenance_ends_at, false)) : 300;

            throw new ApiException('maintenance', $maintenance['message'] ?: 'IronPulse is down for maintenance. Please try again shortly.', 503, $maintenance, ['Retry-After' => (string) $retry]);
        }

        $version = (string) $request->header('X-App-Version');
        if ($version !== '' && $this->versions->status($policy, $version) === AppVersionService::UPDATE_REQUIRED) {
            throw new ApiException('upgrade_required', 'This version of the app is no longer supported. Please update to continue.', 426, $this->versions->describe($policy, $version));
        }

        return $next($request);
    }
}
