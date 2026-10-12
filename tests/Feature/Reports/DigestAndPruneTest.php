<?php

use App\Mail\WeeklyDigestMail;
use App\Models\NotificationSetting;
use App\Models\ProgressPhoto;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\ProgressPhotoService;
use App\Services\WeeklyReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    seedLibrary();
    seedBadges();
    Mail::fake();
    Storage::fake(ProgressPhotoService::DISK);
    Carbon::setTestNow('2026-10-18 18:05:00'); // Sunday evening, UTC
});
afterEach(fn () => Carbon::setTestNow());

function digestUser(array $settings = [], array $profile = []): User
{
    $user = User::factory()->onboarded($profile)->create();
    NotificationSetting::create(array_merge(['user_id' => $user->id, 'email_digest' => true, 'digest_day' => 7], $settings));

    return $user;
}

// ---------------------------------------------------------------------------------------------
// Digest
// ---------------------------------------------------------------------------------------------

test('the digest goes to opted in users at 18:00 on their digest day, once per week', function () {
    $user = digestUser();
    digestUser(['email_digest' => false]);

    $this->artisan('ironpulse:send-digests')->assertSuccessful();
    Mail::assertSent(WeeklyDigestMail::class, 1);
    Mail::assertSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $m) => $m->hasTo($user->email) && $m->report['period']['start'] === '2026-10-12');
    expect(WeeklyReport::whereNotNull('digest_sent_at')->count())->toBe(1);

    $this->artisan('ironpulse:send-digests')->assertSuccessful();
    Mail::assertSent(WeeklyDigestMail::class, 1);                      // not twice
});

test('the digest waits for the right weekday and the right hour in the users own timezone', function () {
    $karachi = digestUser([], ['timezone' => 'Asia/Karachi']);     // 23:05 on Sunday there
    $this->artisan('ironpulse:send-digests')->assertSuccessful();
    Mail::assertNothingSent();

    Carbon::setTestNow('2026-10-18 13:05:00');                         // 18:05 Sunday in Karachi
    $this->artisan('ironpulse:send-digests')->assertSuccessful();
    Mail::assertSent(WeeklyDigestMail::class, fn ($m) => $m->hasTo($karachi->email));

    Mail::fake();
    Carbon::setTestNow('2026-10-19 18:05:00');                         // Monday: not Sunday
    $this->artisan('ironpulse:send-digests')->assertSuccessful();
    Mail::assertNothingSent();
});

test('a digest set for another weekday reports the last complete week', function () {
    $user = digestUser(['digest_day' => 1]);
    Carbon::setTestNow('2026-10-19 18:05:00');                         // Monday evening

    $this->artisan('ironpulse:send-digests')->assertSuccessful();

    Mail::assertSent(WeeklyDigestMail::class, fn ($m) => $m->hasTo($user->email) && $m->report['period']['start'] === '2026-10-12' && $m->report['period']['complete']);
});

test('guests and unfinished accounts never get email', function () {
    $guest = User::factory()->guest()->onboarded()->create();
    NotificationSetting::create(['user_id' => $guest->id, 'email_digest' => true, 'digest_day' => 7]);
    $fresh = User::factory()->create();
    NotificationSetting::create(['user_id' => $fresh->id, 'email_digest' => true, 'digest_day' => 7]);

    $this->artisan('ironpulse:send-digests --force')->assertSuccessful();

    Mail::assertNothingSent();
});

test('dry run lists recipients without sending or marking anything', function () {
    digestUser();

    $this->artisan('ironpulse:send-digests --dry-run')->expectsOutputToContain('Would send')->assertSuccessful();

    Mail::assertNothingSent();
    expect(WeeklyReport::whereNotNull('digest_sent_at')->count())->toBe(0);
});

test('the email shows the numbers and carries a one click unsubscribe', function () {
    $user = digestUser();
    $mail = new WeeklyDigestMail($user, app(WeeklyReportService::class)->build($user->load('profile'), Carbon::parse('2026-10-12')->toImmutable()), 'https://app.test/reports/2026-10-12');

    $html = $mail->render();

    expect($html)->toContain('planned sessions done')->toContain('Open the full report')->toContain('/digest/unsubscribe/'.$user->id)
        ->and($mail->headers()->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
});

test('unsubscribing needs the signed link, asks first on GET and acts on POST', function () {
    $user = digestUser();
    $url = URL::signedRoute('digest.unsubscribe', ['user' => $user->id]);

    $this->get("/digest/unsubscribe/{$user->id}")->assertForbidden();
    $this->post("/digest/unsubscribe/{$user->id}")->assertForbidden();

    $this->get($url)->assertOk()->assertSee('Stop the weekly email?');
    expect($user->notificationSetting()->first()->email_digest)->toBeTrue();   // GET alone changes nothing

    $this->post($url)->assertOk()->assertSee('You are unsubscribed');
    expect($user->notificationSetting()->first()->email_digest)->toBeFalse();
});

test('a signed link for one user cannot be reused for another', function () {
    $a = digestUser();
    $b = digestUser();
    $url = URL::signedRoute('digest.unsubscribe', ['user' => $a->id]);

    $this->post(str_replace("/{$a->id}?", "/{$b->id}?", $url))->assertForbidden();
    expect($b->notificationSetting()->first()->email_digest)->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Guests, last seen and file cleanup
// ---------------------------------------------------------------------------------------------

test('prune removes idle guests and their photos but keeps active guests and real accounts', function () {
    $idle = User::factory()->guest()->create(['created_at' => now()->subDays(90)]);
    $idle->forceFill(['last_seen_at' => now()->subDays(45)])->save();
    $neverSeen = User::factory()->guest()->create(['created_at' => now()->subDays(60)]);
    $active = User::factory()->guest()->create(['created_at' => now()->subDays(90)]);
    $active->forceFill(['last_seen_at' => now()->subDays(2)])->save();
    $newGuest = User::factory()->guest()->create(['created_at' => now()->subDay()]);
    $realOld = User::factory()->create(['created_at' => now()->subDays(400)]);

    Storage::disk(ProgressPhotoService::DISK)->put("progress/{$idle->id}/a.jpg", 'x');
    ProgressPhoto::create(['user_id' => $idle->id, 'taken_on' => '2026-09-01', 'pose' => 'front', 'path' => "progress/{$idle->id}/a.jpg", 'mime' => 'image/jpeg', 'size_bytes' => 1]);
    Storage::disk(ProgressPhotoService::DISK)->put("progress/{$active->id}/b.jpg", 'x');

    $this->artisan('ironpulse:prune-guests')->expectsOutputToContain('Deleted 2 guest')->assertSuccessful();

    expect(User::pluck('id')->all())->toEqualCanonicalizing([$active->id, $newGuest->id, $realOld->id]);
    Storage::disk(ProgressPhotoService::DISK)->assertMissing("progress/{$idle->id}/a.jpg");
    Storage::disk(ProgressPhotoService::DISK)->assertExists("progress/{$active->id}/b.jpg");
    expect(ProgressPhoto::count())->toBe(0);
});

test('prune dry run changes nothing and the limit can be overridden', function () {
    $guest = User::factory()->guest()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('ironpulse:prune-guests --dry-run')->expectsOutputToContain('Would delete 0')->assertSuccessful();
    $this->artisan('ironpulse:prune-guests --days=5 --dry-run')->expectsOutputToContain('Would delete 1')->assertSuccessful();
    expect(User::whereKey($guest->id)->exists())->toBeTrue();

    $this->artisan('ironpulse:prune-guests --days=5')->assertSuccessful();
    expect(User::whereKey($guest->id)->exists())->toBeFalse();
});

test('deleting an account removes its stored photos', function () {
    $user = User::factory()->create();
    Storage::disk(ProgressPhotoService::DISK)->put("progress/{$user->id}/a.jpg", 'x');

    $user->delete();

    Storage::disk(ProgressPhotoService::DISK)->assertMissing("progress/{$user->id}/a.jpg");
});

test('last seen is recorded on a visit and not rewritten within the hour', function () {
    $user = User::factory()->onboarded()->create();
    expect($user->last_seen_at)->toBeNull();

    $this->actingAs($user)->get('/dashboard');
    $first = $user->fresh()->last_seen_at;
    expect($first)->not->toBeNull();

    Carbon::setTestNow(now()->addMinutes(20));
    $this->actingAs($user->fresh())->get('/dashboard');
    expect($user->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(60));
    $this->actingAs($user->fresh())->get('/dashboard');
    expect($user->fresh()->last_seen_at->gt($first))->toBeTrue();
});
