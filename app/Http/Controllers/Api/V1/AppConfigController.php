<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityLevel;
use App\Enums\DietPreference;
use App\Enums\FitnessLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\UnitSystem;
use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\AppVersionPolicy;
use App\Services\App\AppVersionService;
use App\Services\Membership\PaymentVerifierRegistry;
use App\Support\AppSettingKeys;
use App\Support\Entitlement;
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
            'profile_options' => $this->profileOptions(),
            // Names and one-line descriptions of what a membership can unlock, so the paywall never hard-codes copy.
            'entitlements' => array_map(fn (Entitlement $e) => ['key' => $e->value, 'label' => $e->label(), 'description' => $e->description()], Entitlement::cases()),
            'limits' => collect(Entitlement::LIMITS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
        ]]);
    }

    /**
     * The choices the onboarding / profile forms offer, so the apps never hard-code them (and a new value added on the
     * server shows up without an app release). Values are what BodyProfileRequest accepts.
     *
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    private function profileOptions(): array
    {
        $enum = fn (array $cases) => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], $cases);
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        return [
            'sex' => $enum(Sex::cases()),
            'activity_level' => $enum(ActivityLevel::cases()),
            'fitness_level' => $enum(FitnessLevel::cases()),
            'goal' => $enum(Goal::cases()),
            'diet_preference' => $enum(DietPreference::cases()),
            'unit_system' => $enum(UnitSystem::cases()),
            'equipment' => collect(config('ironpulse.equipment'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all(),
            'training_days' => collect($days)->map(fn ($label, $i) => ['value' => $i + 1, 'label' => $label])->all(),
        ];
    }
}
