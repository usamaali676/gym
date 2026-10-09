<?php

use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\ReminderConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPlan();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday
});
afterEach(fn () => Carbon::setTestNow());

test('the manifest is valid and every icon it lists exists on disk', function () {
    $res = $this->get('/manifest.webmanifest')->assertOk();
    $m = $res->json();

    expect($res->headers->get('Content-Type'))->toContain('application/manifest+json')
        ->and($m['name'])->toBe(config('app.name'))
        ->and($m['display'])->toBe('standalone')
        ->and($m['start_url'])->toEndWith('/dashboard')
        ->and($m['theme_color'])->toBe('#07090A')
        ->and(collect($m['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and(collect($m['icons'])->pluck('purpose')->all())->toContain('maskable');

    foreach ($m['icons'] as $icon) {
        $path = public_path(Str::after(parse_url($icon['src'], PHP_URL_PATH), '/'));
        expect(file_exists($path))->toBeTrue("missing icon {$icon['src']}");
    }
    foreach ($m['shortcuts'] as $shortcut) {
        expect($shortcut['url'])->toStartWith('http');
    }
});

test('png icons really have the sizes the manifest claims', function () {
    foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'icon-maskable-512.png' => 512, 'apple-touch-icon.png' => 180] as $file => $size) {
        [$w, $h] = getimagesize(public_path("icons/{$file}"));
        expect([$w, $h])->toBe([$size, $size]);
    }
});

test('the offline page is public and does not depend on any other asset', function () {
    $html = $this->get('/offline')->assertOk()->assertSee('You are offline')->getContent();

    expect($html)->not->toContain('/build/assets')->and($html)->not->toContain('<script src');
});

test('every layout links the manifest and tells the worker whether anyone is signed in', function () {
    $this->get('/')->assertOk()->assertSee('rel="manifest"', false)->assertSee('name="ip-auth" content="0"', false);

    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertSee('name="ip-auth" content="1"', false)
        ->assertSee('name="ip-sw"', false)
        ->assertSee('rel="apple-touch-icon"', false);
});

test('the service worker file is served from the web root so it can control the whole app', function () {
    expect(file_exists(public_path('sw.js')))->toBeTrue();
    expect(file_get_contents(public_path('sw.js')))->toContain("'api/'")->toContain('clear-pages');
});

// ---------------------------------------------------------------------------------------------
// Reminder config
// ---------------------------------------------------------------------------------------------

function reminderUser(array $settings = []): User
{
    $user = User::factory()->onboarded()->create()->load('profile');
    NotificationSetting::create(['user_id' => $user->id] + $settings);

    return $user;
}

test('no reminder config is produced until browser notifications are switched on', function () {
    $user = reminderUser(['browser_notifications' => false, 'workout_reminder_at' => '18:00']);

    expect(app(ReminderConfigService::class)->forUser($user))->toBeNull();
});

test('the config carries times, water interval, timezone and links', function () {
    $user = reminderUser(['browser_notifications' => true, 'workout_reminder_at' => '18:00:00', 'creatine_reminder_at' => '09:30:00', 'water_reminders' => true, 'water_interval_minutes' => 120]);

    $cfg = app(ReminderConfigService::class)->forUser($user);

    expect($cfg['workout'])->toBe('18:00')->and($cfg['creatine'])->toBe('09:30')->and($cfg['sleep'])->toBeNull()
        ->and($cfg['water'])->toBe(120)->and($cfg['tz'])->toBe('UTC')->and($cfg['urls']['state'])->toEndWith('/api/habits');
});

test('the workout reminder is dropped on rest days and once the session is done', function () {
    $user = reminderUser(['browser_notifications' => true, 'workout_reminder_at' => '18:00']);
    $svc = app(ReminderConfigService::class);

    expect($svc->forUser($user)['workout'])->toBe('18:00');                       // Wednesday is a training day

    WorkoutSession::create(['user_id' => $user->id, 'scheduled_for' => '2026-10-14', 'status' => 'completed', 'client_uuid' => (string) Str::uuid()]);
    expect($svc->forUser($user)['workout'])->toBeNull();

    Carbon::setTestNow('2026-10-17 12:00:00');                                      // Saturday: rest day in the plan
    expect($svc->forUser($user->fresh())['workout'])->toBeNull();
});

test('the config is only rendered into the page when reminders are on', function () {
    $off = reminderUser(['browser_notifications' => false]);
    $this->actingAs($off)->get('/dashboard')->assertOk()->assertDontSee('id="reminder-config"', false);

    $on = reminderUser(['browser_notifications' => true, 'creatine_reminder_at' => '09:00']);
    $this->actingAs($on)->get('/dashboard')->assertOk()->assertSee('id="reminder-config"', false)->assertSee('"creatine":"09:00"', false);
});
