<?php

use App\Jobs\SendAnnouncementPush;
use App\Models\Announcement;
use App\Models\Device;
use App\Models\MembershipPlan;
use App\Models\NotificationSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipService;
use App\Services\Push\FcmPushProvider;
use App\Services\Push\NullPushProvider;
use App\Services\Push\PushMessage;
use App\Services\Push\PushProvider;
use App\Services\Push\PushService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePushProvider;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 12:00:00');
    $this->user = User::factory()->onboarded(['timezone' => 'UTC'])->create();
    $login = $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password', 'device' => deviceBlock()])->assertOk();
    $this->token = $login->json('data.token.access_token');
    $this->h = fn () => bearer($this->token);
    $this->fake = new FakePushProvider;
    app()->instance(PushProvider::class, $this->fake);
    $this->push = fn (string $category = 'announcements') => app(PushService::class)->notify($this->user, new PushMessage($category, 'Leg day moved', 'Friday is now legs', ['route' => 'plan']));
});
afterEach(fn () => Carbon::setTestNow());

function register(string $token = 'fcm-token-aaaaaaaaaaaaaaaaaaaa'): array
{
    return ['provider' => 'fcm', 'token' => $token];
}

describe('preferences', function () {
    it('starts with sensible defaults: reminders on, nutrition off, lock-screen details off', function () {
        $p = $this->getJson('/api/v1/me/notifications', ($this->h)())->assertOk()->json('data');

        expect($p['categories'])->toBe(['workout_reminders' => true, 'membership' => true, 'achievements' => true, 'announcements' => true, 'nutrition' => false])
            ->and($p['lock_screen_details'])->toBeFalse()->and($p['quiet_hours'])->toBeNull()->and($p['push_available'])->toBeFalse()->and($p['push_registered'])->toBeFalse();
    });

    it('stores opt-ins on the server so every device agrees', function () {
        $this->putJson('/api/v1/me/notifications', ['categories' => ['announcements' => false, 'nutrition' => true], 'quiet_hours' => ['start' => '22:00', 'end' => '07:00'], 'lock_screen_details' => true], ($this->h)())
            ->assertOk()->assertJsonPath('data.categories.announcements', false)->assertJsonPath('data.categories.nutrition', true)->assertJsonPath('data.quiet_hours.start', '22:00')->assertJsonPath('data.lock_screen_details', true);

        $second = $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password', 'device' => deviceBlock()])->json('data.token.access_token');
        $this->getJson('/api/v1/me/notifications', bearer($second))->assertJsonPath('data.categories.announcements', false);

        $this->putJson('/api/v1/me/notifications', ['quiet_hours' => null], ($this->h)())->assertOk()->assertJsonPath('data.quiet_hours', null);
    });

    it('validates input', function () {
        $put = fn (array $b) => $this->putJson('/api/v1/me/notifications', $b, ($this->h)());

        $put(['categories' => ['announcements' => 'maybe']])->assertStatus(422);
        $put(['quiet_hours' => ['start' => '22:00']])->assertStatus(422);
        $put(['quiet_hours' => ['start' => '22:00', 'end' => '22:00']])->assertStatus(422);
        $put(['quiet_hours' => ['start' => '25:00', 'end' => '07:00']])->assertStatus(422);
    });
});

describe('device token', function () {
    it('registers the push token of the signed-in phone only', function () {
        $r = $this->putJson('/api/v1/me/device/push', register(), ($this->h)())->assertOk();

        expect($r->json('data.device.push_enabled'))->toBeTrue()->and($r->json('data.device.is_current'))->toBeTrue()
            ->and(Device::first()->push_token)->toBe('fcm-token-aaaaaaaaaaaaaaaaaaaa')->and(Device::first()->push_provider)->toBe('fcm');
        $this->getJson('/api/v1/me/notifications', ($this->h)())->assertJsonPath('data.push_registered', true);
        expect($r->getContent())->not->toContain('fcm-token-aaaa'); // the token is never echoed back
    });

    it('stores the token encrypted at rest', function () {
        $this->putJson('/api/v1/me/device/push', register(), ($this->h)());

        expect(DB::table('devices')->value('push_token'))->not->toContain('fcm-token')->and(DB::table('devices')->value('push_token_hash'))->toBe(hash('sha256', 'fcm-token-aaaaaaaaaaaaaaaaaaaa'));
    });

    it('moves a token to the new account when the phone changes hands', function () {
        $this->putJson('/api/v1/me/device/push', register(), ($this->h)())->assertOk();
        $other = User::factory()->onboarded()->create();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password', 'device' => deviceBlock()])->json('data.token.access_token');

        $this->putJson('/api/v1/me/device/push', register(), bearer($login))->assertOk();

        expect(Device::where('user_id', $this->user->id)->first()->push_token)->toBeNull()->and(Device::where('user_id', $other->id)->first()->push_token)->not->toBeNull();
    });

    it('forgets the token on request and on sign-out', function () {
        $this->putJson('/api/v1/me/device/push', register(), ($this->h)());
        $this->deleteJson('/api/v1/me/device/push', [], ($this->h)())->assertOk();
        expect(Device::first()->push_token)->toBeNull();

        $this->putJson('/api/v1/me/device/push', register(), ($this->h)());
        $this->postJson('/api/v1/auth/logout', [], ($this->h)())->assertOk();
        expect(Device::first()->push_token)->toBeNull();
    });

    it('validates the token', function () {
        $this->putJson('/api/v1/me/device/push', ['provider' => 'sms', 'token' => 'x'], ($this->h)())->assertStatus(422);
        $this->putJson('/api/v1/me/device/push', ['provider' => 'fcm', 'token' => 'short'], ($this->h)())->assertStatus(422);
    });
});

describe('delivery rules', function () {
    beforeEach(fn () => $this->putJson('/api/v1/me/device/push', register(), ($this->h)()));

    it('sends nothing the member switched off', function () {
        $this->putJson('/api/v1/me/notifications', ['categories' => ['announcements' => false]], ($this->h)());

        expect(($this->push)('announcements'))->toBe(['sent' => 0, 'skipped' => 1, 'failed' => 0])->and($this->fake->sent)->toBe([]);
        expect(($this->push)('membership')['sent'])->toBe(1);
    });

    it('keeps lock screens generic by default and moves the real text out of the payload', function () {
        ($this->push)('announcements');

        $sent = $this->fake->sent[0];
        expect($sent['title'])->toBe('IronPulse')->and($sent['body'])->not->toContain('Friday')
            ->and(json_encode($sent['data']))->not->toContain('Leg day')->not->toContain('Friday')
            ->and($sent['data'])->toMatchArray(['category' => 'announcements', 'route' => 'plan']);
    });

    it('shows details on the lock screen only when the member asks for it', function () {
        $this->putJson('/api/v1/me/notifications', ['lock_screen_details' => true], ($this->h)());

        ($this->push)('announcements');

        expect($this->fake->sent[0]['title'])->toBe('Leg day moved')->and($this->fake->sent[0]['body'])->toBe('Friday is now legs');
    });

    it('stays quiet during quiet hours, including a window that crosses midnight', function () {
        $this->putJson('/api/v1/me/notifications', ['quiet_hours' => ['start' => '22:00', 'end' => '07:00']], ($this->h)());

        foreach (['23:30' => 0, '03:00' => 0, '06:59' => 0, '07:00' => 1, '12:00' => 1, '21:59' => 1, '22:00' => 0] as $time => $expected) {
            Carbon::setTestNow("2026-10-12 {$time}:00");
            $this->fake->sent = [];
            expect(($this->push)('announcements')['sent'])->toBe($expected, "at {$time}");
        }
    });

    it('reads quiet hours in the member\'s own time zone', function () {
        $this->user->profile->update(['timezone' => 'Asia/Karachi']); // UTC+5: 12:00 UTC is 17:00 there
        $this->putJson('/api/v1/me/notifications', ['quiet_hours' => ['start' => '16:00', 'end' => '18:00']], ($this->h)());

        expect(($this->push)('announcements')['sent'])->toBe(0);
        Carbon::setTestNow('2026-10-12 14:00:00'); // 19:00 in Karachi
        expect(($this->push)('announcements')['sent'])->toBe(1);
    });

    it('drops a token the push service says is dead', function () {
        $this->putJson('/api/v1/me/device/push', register('dead-token-aaaaaaaaaaaaaaaaaaa'), ($this->h)());

        ($this->push)('announcements');

        expect(Device::first()->push_token)->toBeNull();
    });

    it('does not push to a phone that signed out', function () {
        $this->postJson('/api/v1/auth/logout', [], ($this->h)());

        expect(($this->push)('announcements')['sent'])->toBe(0);
    });

    it('ignores unknown categories', function () {
        expect(($this->push)('gossip')['sent'])->toBe(0);
    });
});

describe('Firebase provider', function () {
    beforeEach(function () {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['ironpulse.push.project_id' => 'ironpulse-test', 'ironpulse.push.credentials_path' => $path, 'ironpulse.push.enabled' => true]);
        Cache::forget('fcm.access_token');
        $this->device = (new Device)->forceFill(['push_token' => 'tok-1234567890abcdef']);
    });

    it('signs in with the service account and posts a v1 message', function () {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/ironpulse-test/messages/1']),
        ]);

        $result = (new FcmPushProvider)->send($this->device, 'IronPulse', 'You have a new update.', ['route' => 'plan', 'n' => 3]);

        expect($result)->toBe('sent');
        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'oauth2')) {
                $jwt = explode('.', $request['assertion']);
                $claims = json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true);

                return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && count($jwt) === 3
                    && $claims['iss'] === 'svc@project.iam.gserviceaccount.com' && str_contains($claims['scope'], 'firebase.messaging');
            }

            return $request->url() === 'https://fcm.googleapis.com/v1/projects/ironpulse-test/messages:send'
                && $request->hasHeader('Authorization', 'Bearer ya29.fake')
                && $request['message']['token'] === 'tok-1234567890abcdef'
                && $request['message']['notification'] === ['title' => 'IronPulse', 'body' => 'You have a new update.']
                && $request['message']['data'] === ['route' => 'plan', 'n' => '3'];
        });
    });

    it('reuses the access token between messages', function () {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake']), 'fcm.googleapis.com/*' => Http::response([])]);
        $provider = new FcmPushProvider;

        $provider->send($this->device, 't', 'b', []);
        $provider->send($this->device, 't', 'b', []);

        Http::assertSentCount(3); // one token request, two messages
    });

    it('tells a dead token from a temporary failure', function () {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x']),
            'fcm.googleapis.com/*' => Http::sequence()->push(['error' => ['status' => 'UNREGISTERED']], 404)->push(['error' => ['status' => 'UNAVAILABLE']], 503),
        ]);
        $provider = new FcmPushProvider;

        expect($provider->send($this->device, 't', 'b', []))->toBe('invalid_token')->and($provider->send($this->device, 't', 'b', []))->toBe('failed');
    });

    it('does nothing without credentials or a token', function () {
        config(['ironpulse.push.credentials_path' => '/nonexistent.json']);
        Http::fake();

        expect((new FcmPushProvider)->send($this->device, 't', 'b', []))->toBe('failed')->and((new FcmPushProvider)->send(new Device, 't', 'b', []))->toBe('skipped');
        Http::assertNothingSent();
    });

    it('is only chosen when the server is configured for it', function () {
        app()->forgetInstance(PushProvider::class); // drop the fake the other tests bind
        config(['ironpulse.push.enabled' => false]);
        expect(app()->make(PushProvider::class))->toBeInstanceOf(NullPushProvider::class);
        config(['ironpulse.push.enabled' => true]);
        expect(app()->make(PushProvider::class))->toBeInstanceOf(FcmPushProvider::class);
    });
});

describe('announcement push', function () {
    beforeEach(function () {
        $this->putJson('/api/v1/me/device/push', register(), ($this->h)());
        $this->admin = User::factory()->onboarded()->admin()->create();
    });

    it('queues one push for a published announcement and never a second', function () {
        config(['ironpulse.push.enabled' => true]);
        $a = Announcement::create(['title' => 'New program', 'body' => 'Check it out', 'status' => 'published']);

        $this->actingAs($this->admin)->post(route('admin.announcements.push', $a))->assertSessionHasNoErrors();
        (new SendAnnouncementPush($a->id))->handle(app(PushService::class), app(EntitlementService::class));

        expect($this->fake->sent)->toHaveCount(1)->and($a->fresh()->push_sent_at)->not->toBeNull();
        (new SendAnnouncementPush($a->id))->handle(app(PushService::class), app(EntitlementService::class));
        expect($this->fake->sent)->toHaveCount(1);
        $this->actingAs($this->admin)->post(route('admin.announcements.push', $a))->assertSessionHasErrors('status');
    });

    it('respects the audience', function () {
        $premium = MembershipPlan::factory()->create(['slug' => 'premium']);
        $a = Announcement::create(['title' => 'VIP', 'body' => 'x', 'status' => 'published', 'audience' => ['premium']]);

        (new SendAnnouncementPush($a->id))->handle(app(PushService::class), app(EntitlementService::class));
        expect($this->fake->sent)->toBe([]);

        $a->update(['push_sent_at' => null]);
        app(MembershipService::class)->grant($this->user, $premium, null);
        (new SendAnnouncementPush($a->id))->handle(app(PushService::class), app(EntitlementService::class));
        expect($this->fake->sent)->toHaveCount(1);
    });

    it('refuses drafts and servers without push configured', function () {
        $draft = Announcement::create(['title' => 'D', 'body' => 'x', 'status' => 'draft']);
        config(['ironpulse.push.enabled' => true]);
        $this->actingAs($this->admin)->post(route('admin.announcements.push', $draft))->assertSessionHasErrors('status');

        $live = Announcement::create(['title' => 'L', 'body' => 'x', 'status' => 'published']);
        config(['ironpulse.push.enabled' => false]);
        $this->actingAs($this->admin)->post(route('admin.announcements.push', $live))->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->get(route('admin.announcements.edit', $live))->assertOk()->assertSee('Push is not set up');
    });

    it('is admin only', function () {
        $a = Announcement::create(['title' => 'L', 'body' => 'x', 'status' => 'published']);

        $this->actingAs($this->user)->post(route('admin.announcements.push', $a))->assertForbidden();
    });
});

describe('membership reminders', function () {
    it('warns once, shortly before a membership ends, and marks lapsed ones expired', function () {
        $this->putJson('/api/v1/me/device/push', register(), ($this->h)());
        $plan = MembershipPlan::factory()->create();
        $soon = app(MembershipService::class)->grant($this->user, $plan, now()->addDays(2));
        $far = app(MembershipService::class)->grant(User::factory()->onboarded()->create(), $plan, now()->addDays(30));
        $gone = app(MembershipService::class)->grant(User::factory()->onboarded()->create(), $plan, now()->addDay());
        $gone->forceFill(['ends_at' => now()->subHour()])->save();

        $this->artisan('membership:maintain')->assertSuccessful();

        expect($this->fake->sent)->toHaveCount(1)->and($soon->fresh()->expiry_reminded_at)->not->toBeNull()->and($far->fresh()->expiry_reminded_at)->toBeNull()
            ->and($gone->fresh()->status)->toBe('expired')->and(Subscription::where('status', 'expired')->count())->toBe(1);
        $this->artisan('membership:maintain')->assertSuccessful();
        expect($this->fake->sent)->toHaveCount(1);
    });

    it('does not remind members whose store subscription renews itself', function () {
        $this->putJson('/api/v1/me/device/push', register(), ($this->h)());
        $sub = app(MembershipService::class)->grant($this->user, MembershipPlan::factory()->create(), now()->addDay());
        $sub->forceFill(['auto_renew' => true])->save();

        $this->artisan('membership:maintain')->assertSuccessful();

        expect($this->fake->sent)->toBe([]);
    });
});

describe('schedule', function () {
    it('registers the housekeeping jobs', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('membership:maintain')->expectsOutputToContain('api:prune')->expectsOutputToContain('sanctum:prune-expired')->assertSuccessful();
    });
});

it('keeps notification preferences private to the member', function () {
    NotificationSetting::updateOrCreate(['user_id' => $this->user->id], ['push_nutrition' => true]);
    $other = User::factory()->onboarded()->create();

    expect($this->getJson('/api/v1/me/notifications', apiAs($other))->json('data.categories.nutrition'))->toBeFalse();
});
