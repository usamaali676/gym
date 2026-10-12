<?php

use App\Models\AppRelease;
use App\Models\AppSetting;
use App\Models\AppVersionPolicy;
use App\Models\User;
use App\Services\App\AppVersionService;

function androidHeaders(string $version = '1.0.0'): array
{
    return ['X-App-Platform' => 'android', 'X-App-Version' => $version];
}

beforeEach(function () {
    AppVersionPolicy::forPlatform('android')->update(['min_supported' => '1.2.0', 'recommended' => '1.4.0', 'latest' => '1.5.0', 'store_url' => 'https://play.google.com/store/apps/details?id=app.ironpulse', 'release_notes' => 'Faster sync']);
    AppVersionPolicy::forPlatform('ios')->update(['min_supported' => '1.0.0']);
});

describe('version policy', function () {
    it('tells each build whether it is current, should update or must update', function () {
        $status = fn (string $v) => $this->getJson('/api/v1/app/config', androidHeaders($v))->assertOk()->json('data.version_policy.status');

        expect($status('1.5.0'))->toBe('ok')
            ->and($status('1.4.0'))->toBe('ok')
            ->and($status('1.3.9'))->toBe('update_recommended')
            ->and($status('1.2.0'))->toBe('update_recommended')
            ->and($status('1.1.9'))->toBe('update_required')
            ->and($status('0.9.0'))->toBe('update_required');
    });

    it('describes both platforms and the update details', function () {
        $data = $this->getJson('/api/v1/app/config', androidHeaders('1.1.0'))->json('data');

        expect($data['version_policy'])->toMatchArray(['platform' => 'android', 'status' => 'update_required', 'min_supported' => '1.2.0', 'latest' => '1.5.0', 'release_notes' => 'Faster sync'])
            ->and(array_keys($data['policies']))->toEqualCanonicalizing(['android', 'ios']);
    });

    it('applies the platform-specific rule', function () {
        $ios = $this->getJson('/api/v1/app/config', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.0.0'])->json('data.version_policy.status');

        expect($ios)->toBe('ok');
    });

    it('blocks builds below the minimum with 426 everywhere except the config endpoint', function () {
        $user = User::factory()->onboarded()->create();

        $this->getJson('/api/v1/membership/plans', androidHeaders('1.1.0'))
            ->assertStatus(426)->assertJsonPath('error.code', 'upgrade_required')->assertJsonPath('error.details.min_supported', '1.2.0')
            ->assertJsonPath('error.details.store_url', 'https://play.google.com/store/apps/details?id=app.ironpulse');
        $this->getJson('/api/v1/me', apiAs($user) + androidHeaders('1.1.0'))->assertStatus(426);
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.co', 'password' => 'x', 'device' => deviceBlock()], androidHeaders('1.1.0'))->assertStatus(426);

        $this->getJson('/api/v1/app/config', androidHeaders('1.1.0'))->assertOk();
        $this->getJson('/api/v1/membership/plans', androidHeaders('1.2.0'))->assertOk();
    });

    it('cannot judge a client that does not identify itself, and says so by not blocking it', function () {
        $this->getJson('/api/v1/membership/plans')->assertOk();
        $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('data.version_policy', null);
        $this->getJson('/api/v1/membership/plans', ['X-App-Platform' => 'android'])->assertOk();
    });

    it('understands build suffixes and does not lock people out over odd formats', function () {
        $svc = app(AppVersionService::class);
        $policy = AppVersionPolicy::forPlatform('android');

        expect(AppVersionService::normalise('1.2.3+45'))->toBe('1.2.3')
            ->and(AppVersionService::normalise('v1.2'))->toBe('1.2')
            ->and(AppVersionService::normalise('banana'))->toBeNull()
            ->and($svc->status($policy, '1.9.0+7'))->toBe('ok')
            ->and($svc->status($policy, 'banana'))->toBe('ok')
            ->and($svc->status($policy, '1.10.0'))->toBe('ok'); // 1.10 is newer than 1.4, not older
    });
});

describe('maintenance', function () {
    it('answers 503 with Retry-After while maintenance is on, but still serves the config', function () {
        AppVersionPolicy::forPlatform('android')->update(['maintenance_enabled' => true, 'maintenance_message' => 'Upgrading servers', 'maintenance_ends_at' => now()->addMinutes(30)]);

        $this->getJson('/api/v1/membership/plans', androidHeaders('1.5.0'))
            ->assertStatus(503)->assertJsonPath('error.code', 'maintenance')->assertJsonPath('error.message', 'Upgrading servers')->assertHeader('Retry-After');

        $this->getJson('/api/v1/app/config', androidHeaders('1.5.0'))->assertOk()
            ->assertJsonPath('data.maintenance.enabled', true)->assertJsonPath('data.maintenance.message', 'Upgrading servers');
        // iOS is unaffected
        $this->getJson('/api/v1/membership/plans', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.0.0'])->assertOk();
    });

    it('lifts itself when the end time passes', function () {
        AppVersionPolicy::forPlatform('android')->update(['maintenance_enabled' => true, 'maintenance_ends_at' => now()->subMinute()]);

        $this->getJson('/api/v1/membership/plans', androidHeaders('1.5.0'))->assertOk();
        $this->getJson('/api/v1/app/config', androidHeaders('1.5.0'))->assertJsonPath('data.maintenance.enabled', false);
    });
});

describe('config content', function () {
    it('lists the latest published release notes for the platform', function () {
        AppRelease::create(['platform' => 'android', 'version' => '1.5.0', 'notes' => 'New demos', 'is_published' => true, 'published_at' => now()]);
        AppRelease::create(['platform' => 'ios', 'version' => '1.4.0', 'notes' => 'iOS only', 'is_published' => true, 'published_at' => now()]);
        AppRelease::create(['platform' => 'all', 'version' => '1.3.0', 'notes' => 'Everyone', 'is_published' => true, 'published_at' => now()->subDay()]);
        AppRelease::create(['platform' => 'android', 'version' => '1.6.0', 'notes' => 'Unreleased', 'is_published' => false]);

        $notes = collect($this->getJson('/api/v1/app/config', androidHeaders('1.5.0'))->json('data.releases'))->pluck('notes')->all();

        expect($notes)->toBe(['New demos', 'Everyone']);
    });

    it('merges dashboard settings over registered defaults and ignores unknown keys', function () {
        AppSetting::create(['key' => 'support.email', 'value' => 'help@example.com']);
        AppSetting::create(['key' => 'features.leaderboard', 'value' => false]);
        AppSetting::create(['key' => 'not.a.real.key', 'value' => 'x']);
        AppSetting::create(['key' => 'support.url', 'value' => 'https://private.example', 'is_public' => false]);

        $settings = $this->getJson('/api/v1/app/config')->json('data.settings');

        expect($settings['support.email'])->toBe('help@example.com')
            ->and($settings['features.leaderboard'])->toBeFalse()
            ->and($settings['features.help_center'])->toBeTrue()
            ->and($settings['support.url'])->toBeNull()
            ->and($settings)->not->toHaveKey('not.a.real.key');
    });

    it('reports which optional services are switched on', function () {
        $features = $this->getJson('/api/v1/app/config')->json('data.features');

        expect($features)->toBe(['coach' => false, 'payments' => [], 'push' => false]);
    });
});

it('publishes the profile form choices so the apps do not hard-code them', function () {
    $options = $this->getJson('/api/v1/app/config')->json('data.profile_options');

    expect(array_keys($options))->toBe(['sex', 'activity_level', 'fitness_level', 'goal', 'diet_preference', 'unit_system', 'equipment', 'training_days'])
        ->and(collect($options['goal'])->pluck('value')->all())->toBe(\App\Enums\Goal::values())
        ->and(collect($options['equipment'])->pluck('value')->all())->toBe(array_keys(config('ironpulse.equipment')))
        ->and($options['training_days'][0])->toBe(['value' => 1, 'label' => 'Monday'])
        ->and($options['training_days'])->toHaveCount(7);
});
