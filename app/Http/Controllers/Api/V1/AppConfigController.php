<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\AppVersionPolicy;
use App\Services\App\AppVersionService;
use App\Services\Membership\PaymentVerifierRegistry;
use App\Support\AppSettingKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public bootstrap data the app reads before sign-in and on every resume: server time, the version / maintenance
 * policy, auth parameters, feature switches and the dashboard-managed settings.
 */
class AppConfigController extends Controller
{
    public function __invoke(Request $request, AppVersionService $versions, PaymentVerifierRegistry $payments): JsonResponse
    {
        $platform = strtolower((string) $request->header('X-App-Platform'));
        $mine = in_array($platform, AppVersionPolicy::PLATFORMS, true) ? AppVersionPolicy::forPlatform($platform) : null;
        $version = (string) $request->header('X-App-Version') ?: null;

        $policies = AppVersionPolicy::query()->get()->keyBy('platform');

        return response()->json(['data' => [
            'api_version' => 'v1',
            'server_time' => now()->toIso8601String(),
            // The caller's own verdict (needs X-App-Platform / X-App-Version), and both platforms for reference.
            'version_policy' => $mine ? $versions->describe($mine, $version) : null,
            'policies' => $policies->map(fn (AppVersionPolicy $p) => $versions->describe($p))->all(),
            'maintenance' => $versions->maintenance($mine),
            'releases' => AppRelease::query()->where('is_published', true)->whereIn('platform', array_filter([$platform ?: null, 'all']) ?: ['all'])
                ->orderByDesc('published_at')->limit(5)->get()
                ->map(fn (AppRelease $r) => ['platform' => $r->platform, 'version' => $r->version, 'notes' => $r->notes, 'published_at' => $r->published_at?->toIso8601String()])->all(),
            'auth' => [
                'guest_mode' => (bool) config('ironpulse.guest_mode'),
                'token_ttl_days' => (int) config('ironpulse.mobile.token_ttl_days'),
                'refresh_window_days' => (int) config('ironpulse.mobile.refresh_window_days'),
            ],
            'features' => [
                'coach' => (bool) config('ironpulse.coach.enabled'),
                'payments' => $payments->available(),
                'push' => (bool) config('ironpulse.push.enabled'),
            ],
            'settings' => AppSettingKeys::publicValues(),
        ]]);
    }
}
