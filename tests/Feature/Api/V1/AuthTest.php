<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sam Lifter',
        'email' => 'sam@example.com',
        'password' => 'Str0ngPassw0rd',
        'device' => deviceBlock(),
    ], $overrides);
}

describe('register', function () {
    it('creates the account, stores the device and returns a working bearer token', function () {
        $response = $this->postJson('/api/v1/auth/register', registerPayload());

        $response->assertCreated()
            ->assertJsonPath('data.token.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'sam@example.com')
            ->assertJsonPath('data.user.onboarded', false)
            ->assertJsonMissingPath('data.user.password');

        $token = $response->json('data.token.access_token');
        expect(Str::after($token, '|'))->toStartWith('ipt_');

        $user = User::where('email', 'sam@example.com')->firstOrFail();
        expect(Hash::check('Str0ngPassw0rd', $user->password))->toBeTrue();
        expect($user->devices()->count())->toBe(1);
        expect(Device::first()->platform)->toBe('android');

        $this->getJson('/api/v1/me', bearer($token))->assertOk()->assertJsonPath('data.id', $user->id);
    });

    it('stores only a hash of the token, never the plain text', function () {
        $token = $this->postJson('/api/v1/auth/register', registerPayload())->json('data.token.access_token');
        $plain = Str::after($token, '|');

        expect(PersonalAccessToken::query()->where('token', $plain)->exists())->toBeFalse()
            ->and(PersonalAccessToken::query()->where('token', hash('sha256', $plain))->exists())->toBeTrue();
    });

    it('rejects bad input with the standard error envelope', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/auth/register', registerPayload(['email' => 'taken@example.com', 'password' => 'short', 'device' => ['platform' => 'windows']]));

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email', 'password', 'device.installation_id', 'device.platform'], 'request_id']]);
    });

    it('normalises the email so the same address cannot register twice', function () {
        $this->postJson('/api/v1/auth/register', registerPayload(['email' => 'Sam@Example.com']))->assertCreated();
        $this->postJson('/api/v1/auth/register', registerPayload(['email' => 'sam@example.com', 'device' => deviceBlock()]))->assertStatus(422);
    });
});

describe('login', function () {
    beforeEach(function () {
        $this->user = User::factory()->onboarded()->create(['email' => 'lift@example.com', 'password' => 'Str0ngPassw0rd']);
    });

    it('signs in with valid credentials', function () {
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'LIFT@example.com', 'password' => 'Str0ngPassw0rd', 'device' => deviceBlock()]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.user.onboarded', true)
            ->assertJsonPath('data.user.profile.goal', 'recomposition');

        $expires = Carbon\Carbon::parse($response->json('data.token.expires_at'));
        expect($expires->between(now()->addDays(59), now()->addDays(61)))->toBeTrue();
        expect(Carbon\Carbon::parse($response->json('data.token.refresh_after'))->lt($expires))->toBeTrue();
    });

    it('answers a wrong password and an unknown email identically', function () {
        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'lift@example.com', 'password' => 'nope', 'device' => deviceBlock()]);
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope', 'device' => deviceBlock()]);

        $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $unknownEmail->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        expect($wrongPassword->json('error.message'))->toBe($unknownEmail->json('error.message'));
    });

    it('locks out after repeated failures for that email and address', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'lift@example.com', 'password' => 'bad', 'device' => deviceBlock()])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'lift@example.com', 'password' => 'Str0ngPassw0rd', 'device' => deviceBlock()])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'too_many_attempts')
            ->assertHeader('Retry-After');
    });

    it('refuses a suspended account at sign-in and on every existing token', function () {
        $token = apiToken($this->user);
        $this->user->forceFill(['status' => 'suspended', 'suspended_reason' => 'Chargeback'])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'lift@example.com', 'password' => 'Str0ngPassw0rd', 'device' => deviceBlock()])
            ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

        $this->getJson('/api/v1/me', bearer($token))->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    });

    it('does not let a guest account sign in with a password', function () {
        $guest = User::factory()->guest()->create();
        expect($guest->password)->toBeNull();

        $this->postJson('/api/v1/auth/login', ['email' => '', 'password' => 'x', 'device' => deviceBlock()])->assertStatus(422);
    });

    it('replaces the previous token when the same install signs in again', function () {
        $device = deviceBlock();
        $first = $this->postJson('/api/v1/auth/login', ['email' => 'lift@example.com', 'password' => 'Str0ngPassw0rd', 'device' => $device])->json('data.token.access_token');
        $second = $this->postJson('/api/v1/auth/login', ['email' => 'lift@example.com', 'password' => 'Str0ngPassw0rd', 'device' => $device])->json('data.token.access_token');

        $this->getJson('/api/v1/me', bearer($first))->assertUnauthorized();
        $this->getJson('/api/v1/me', bearer($second))->assertOk();
        expect(Device::where('user_id', $this->user->id)->count())->toBe(1);
    });
});

describe('guest', function () {
    it('starts a guest account when guest mode is on', function () {
        $response = $this->postJson('/api/v1/auth/guest', ['device' => deviceBlock()])->assertCreated();

        $response->assertJsonPath('data.user.is_guest', true)->assertJsonPath('data.user.email', null);
        $this->getJson('/api/v1/me', bearer($response->json('data.token.access_token')))->assertOk();
    });

    it('is not available when guest mode is off', function () {
        config(['ironpulse.guest_mode' => false]);

        $this->postJson('/api/v1/auth/guest', ['device' => deviceBlock()])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    });

    it('can be claimed into a real account without losing the data', function () {
        $guest = User::factory()->guest()->onboarded()->create();

        $this->postJson('/api/v1/me/claim', ['email' => 'keep@example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd'], apiAs($guest))
            ->assertOk()->assertJsonPath('data.is_guest', false)->assertJsonPath('data.id', $guest->id);
    });
});

describe('tokens', function () {
    it('rejects an expired token', function () {
        $user = User::factory()->onboarded()->create();
        $token = apiToken($user, now()->subMinute());

        $this->getJson('/api/v1/me', bearer($token))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    });

    it('rejects a missing, malformed or unknown token', function () {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me', bearer('garbage'))->assertUnauthorized();
        $this->getJson('/api/v1/me', bearer('ipt_999|'.str_repeat('a', 40)))->assertUnauthorized();
    });

    it('never accepts a web session cookie as authentication', function () {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('rotates on refresh: the old token dies and the new one works', function () {
        $user = User::factory()->onboarded()->create();
        $old = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device' => deviceBlock()])->json('data.token.access_token');

        $new = $this->postJson('/api/v1/auth/refresh', [], bearer($old))->assertOk()->json('data.token.access_token');

        expect($new)->not->toBe($old);
        $this->getJson('/api/v1/me', bearer($old))->assertUnauthorized();
        $this->getJson('/api/v1/me', bearer($new))->assertOk();
        expect(Device::count())->toBe(1);
    });

    it('cannot refresh an expired token', function () {
        $user = User::factory()->onboarded()->create();

        $this->postJson('/api/v1/auth/refresh', [], bearer(apiToken($user, now()->subDay())))->assertUnauthorized();
    });

    it('revokes the token on logout and forgets the push token', function () {
        $user = User::factory()->onboarded()->create();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device' => deviceBlock()])->json('data.token.access_token');
        Device::first()->forceFill(['push_provider' => 'fcm', 'push_token' => 'abc', 'push_token_hash' => hash('sha256', 'abc')])->save();

        $this->postJson('/api/v1/auth/logout', [], bearer($token))->assertOk();

        $this->getJson('/api/v1/me', bearer($token))->assertUnauthorized();
        expect(Device::first()->push_token)->toBeNull()->and(Device::first()->personal_access_token_id)->toBeNull();
    });

    it('signs out everywhere with logout-all', function () {
        $user = User::factory()->onboarded()->create();
        $a = apiToken($user);
        $b = apiToken($user);

        $this->postJson('/api/v1/auth/logout-all', [], bearer($a))->assertOk();

        $this->getJson('/api/v1/me', bearer($b))->assertUnauthorized();
        expect($user->tokens()->count())->toBe(0);
    });

    it('limits the number of signed-in installs by signing out the least recently used', function () {
        config(['ironpulse.mobile.max_devices' => 2]);
        $user = User::factory()->onboarded()->create();
        $tokens = [];
        foreach (range(1, 3) as $i) {
            $tokens[$i] = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device' => deviceBlock()])->json('data.token.access_token');
            Device::query()->latest('id')->first()->forceFill(['last_seen_at' => now()->addMinutes($i)])->save();
        }

        $this->getJson('/api/v1/me', bearer($tokens[1]))->assertUnauthorized();
        $this->getJson('/api/v1/me', bearer($tokens[3]))->assertOk();
    });
});

describe('sessions', function () {
    it('lists only my installs and lets me sign one out', function () {
        $user = User::factory()->onboarded()->create();
        $other = User::factory()->onboarded()->create();
        $mine = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device' => deviceBlock(['name' => 'Pixel'])])->json('data.token.access_token');
        $tablet = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device' => deviceBlock(['name' => 'Tablet'])])->json('data.token.access_token');
        $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password', 'device' => deviceBlock(['name' => 'Spy'])]);

        $list = $this->getJson('/api/v1/auth/sessions', bearer($mine))->assertOk();
        expect(collect($list->json('data'))->pluck('name')->sort()->values()->all())->toBe(['Pixel', 'Tablet']);
        expect(collect($list->json('data'))->firstWhere('name', 'Pixel')['is_current'])->toBeTrue();

        $tabletId = collect($list->json('data'))->firstWhere('name', 'Tablet')['id'];
        $this->deleteJson("/api/v1/auth/sessions/{$tabletId}", [], bearer($mine))->assertOk();
        $this->getJson('/api/v1/me', bearer($tablet))->assertUnauthorized();
    });

    it('cannot sign out another user\'s device', function () {
        $user = User::factory()->onboarded()->create();
        $other = User::factory()->onboarded()->create();
        $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password', 'device' => deviceBlock()]);
        $victim = Device::where('user_id', $other->id)->firstOrFail();

        $this->deleteJson("/api/v1/auth/sessions/{$victim->id}", [], apiAs($user))->assertNotFound();
        expect($victim->fresh()->personal_access_token_id)->not->toBeNull();
    });
});

describe('password', function () {
    it('changing the password signs out the other devices but keeps this one', function () {
        $user = User::factory()->onboarded()->create();
        $here = apiToken($user);
        $there = apiToken($user);

        $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'N3wStr0ngPass'], bearer($here))->assertOk();

        $this->getJson('/api/v1/me', bearer($here))->assertOk();
        $this->getJson('/api/v1/me', bearer($there))->assertUnauthorized();
        expect(Hash::check('N3wStr0ngPass', $user->fresh()->password))->toBeTrue();
    });

    it('requires the current password', function () {
        $user = User::factory()->onboarded()->create();

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'N3wStr0ngPass'], apiAs($user))->assertStatus(422);
    });

    it('does not reveal which emails have accounts when asking for a reset link', function () {
        $user = User::factory()->create(['email' => 'real@example.com']);

        $real = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'real@example.com'])->assertOk();
        $fake = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'fake@example.com'])->assertOk();

        expect($real->json('data.message'))->toBe($fake->json('data.message'));
    });

    it('resets with a valid token, signing the account out everywhere', function () {
        $user = User::factory()->onboarded()->create(['email' => 'reset@example.com']);
        $old = apiToken($user);
        $resetToken = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', ['token' => $resetToken, 'email' => 'reset@example.com', 'password' => 'Br4ndNewPass'])->assertOk();

        expect(Hash::check('Br4ndNewPass', $user->fresh()->password))->toBeTrue();
        $this->getJson('/api/v1/me', bearer($old))->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'Br4ndNewPass', 'device' => deviceBlock()])->assertOk();
    });

    it('rejects an invalid reset token', function () {
        User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/v1/auth/reset-password', ['token' => 'nope', 'email' => 'reset@example.com', 'password' => 'Br4ndNewPass'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');
    });
});

describe('account', function () {
    it('deletes the account and its tokens after the password is confirmed', function () {
        $user = User::factory()->onboarded()->create();
        $token = apiToken($user);

        $this->deleteJson('/api/v1/me', ['password' => 'wrong'], bearer($token))->assertStatus(422);
        expect(User::find($user->id))->not->toBeNull();

        $this->deleteJson('/api/v1/me', ['password' => 'password'], bearer($token))->assertOk();

        expect(User::find($user->id))->toBeNull()->and(PersonalAccessToken::count())->toBe(0);
    });

    it('lets a guest delete the account by typing DELETE', function () {
        $guest = User::factory()->guest()->create();

        $this->deleteJson('/api/v1/me', [], apiAs($guest))->assertStatus(422);
        $this->deleteJson('/api/v1/me', ['confirm' => 'DELETE'], apiAs($guest))->assertOk();
        expect(User::find($guest->id))->toBeNull();
    });

    it('returns only the signed-in user and never private fields', function () {
        $user = User::factory()->onboarded()->create();
        User::factory()->onboarded()->create();

        $json = $this->getJson('/api/v1/me', apiAs($user))->assertOk()->json('data');

        expect($json['id'])->toBe($user->id)
            ->and($json)->not->toHaveKeys(['password', 'remember_token', 'suspended_reason']);
    });
});

describe('onboarding and settings', function () {
    it('blocks onboarded-only routes until the profile exists', function () {
        $user = User::factory()->create();

        $this->getJson('/api/v1/me/settings', apiAs($user))->assertStatus(409)->assertJsonPath('error.code', 'onboarding_required');
    });

    it('completes onboarding with the same rules as the web wizard', function () {
        seedPlan();
        $user = User::factory()->create();

        $this->postJson('/api/v1/me/onboarding', ['age' => 5], apiAs($user))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->postJson('/api/v1/me/onboarding', bodyProfilePayload(), apiAs($user))
            ->assertOk()->assertJsonPath('data.onboarded', true)->assertJsonPath('data.profile.goal', 'recomposition');

        expect($user->fresh()->isOnboarded())->toBeTrue()->and($user->fresh()->profile->plan_id)->not->toBeNull();
    });

    it('reads and updates settings through the shared service', function () {
        seedPlan();
        $user = User::factory()->onboarded()->create();

        $this->putJson('/api/v1/me/settings', ['sound_enabled' => false, 'workout_reminder_at' => '06:45', 'water_reminders' => true, 'water_interval_minutes' => 60], apiAs($user))
            ->assertOk()
            ->assertJsonPath('data.sound_enabled', false)
            ->assertJsonPath('data.notifications.workout_reminder_at', '06:45')
            ->assertJsonPath('data.notifications.water_interval_minutes', 60);

        $this->putJson('/api/v1/me/settings', ['water_interval_minutes' => 5], apiAs($user))->assertStatus(422);
    });
});

describe('envelope and request ids', function () {
    it('echoes a sane inbound request id and generates one otherwise', function () {
        $this->getJson('/api/v1/app/config', ['X-Request-Id' => 'abcd1234-trace'])->assertHeader('X-Request-Id', 'abcd1234-trace');
        $generated = $this->getJson('/api/v1/app/config', ['X-Request-Id' => 'bad id!!'])->headers->get('X-Request-Id');

        expect($generated)->toMatch('/^[0-9a-f-]{36}$/');
    });

    it('puts the request id inside errors too', function () {
        $response = $this->getJson('/api/v1/me', ['X-Request-Id' => 'trace-me-please'])->assertUnauthorized();

        expect($response->json('error.request_id'))->toBe('trace-me-please');
    });

    it('answers unknown paths and wrong methods in the same shape', function () {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->putJson('/api/v1/app/config')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    });

    it('forces JSON even when the client asks for HTML', function () {
        $this->get('/api/v1/me', ['Accept' => 'text/html'])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    });

    it('leaves the web app\'s own error pages alone', function () {
        $this->get('/definitely-not-here')->assertNotFound()->assertSee('404', false);
    });
});

describe('app config', function () {
    it('is public and describes the auth parameters', function () {
        $this->getJson('/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.auth.guest_mode', true)
            ->assertJsonStructure(['data' => ['server_time', 'auth' => ['token_ttl_days', 'refresh_window_days'], 'features']]);
    });
});
