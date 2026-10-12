<?php

use App\Models\Badge;
use App\Models\DailyLog;
use App\Models\Exercise;
use App\Models\FoodLog;
use App\Models\Measurement;
use App\Models\PersonalRecord;
use App\Models\PostureSession;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\WeeklyReport;
use App\Models\WorkoutSession;
use App\Services\WeeklyReportService;
use App\Services\XpService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedLibrary();
    seedBadges();
    Carbon::setTestNow('2026-10-16 12:00:00'); // Friday of the week starting Mon 12 Oct
});
afterEach(fn () => Carbon::setTestNow());

function rUser(array $profile = []): User
{
    return User::factory()->onboarded($profile + ['training_days' => [1, 2, 3, 4, 5]])->create()->load('profile');
}

function monday(string $date = '2026-10-12'): CarbonImmutable
{
    return CarbonImmutable::parse($date)->startOfDay();
}

function doneSession(User $user, string $day, array $over = []): WorkoutSession
{
    return WorkoutSession::create($over + [
        'user_id' => $user->id, 'scheduled_for' => $day, 'status' => 'completed', 'source' => 'game', 'client_uuid' => (string) Str::uuid(),
        'started_at' => $day.' 10:00:00', 'ended_at' => $day.' 11:00:00', 'active_seconds' => 3600, 'volume_kg' => 5000, 'sets_completed' => 18,
    ]);
}

// ---------------------------------------------------------------------------------------------
// Report numbers
// ---------------------------------------------------------------------------------------------

test('training numbers count only completed sessions in the week', function () {
    $user = rUser();
    doneSession($user, '2026-10-12');
    doneSession($user, '2026-10-13', ['volume_kg' => 4000, 'sets_completed' => 15, 'active_seconds' => 1800, 'rating' => 4]);
    doneSession($user, '2026-10-07');                                                             // previous week
    doneSession($user, '2026-10-14', ['status' => 'abandoned']);

    $r = app(WeeklyReportService::class)->build($user, monday());

    expect($r['training']['completed'])->toBe(2)
        ->and($r['training']['planned'])->toBe(5)
        ->and($r['training']['volume_kg'])->toBe(9000)
        ->and($r['training']['sets'])->toBe(33)
        ->and($r['training']['minutes'])->toBe(90)
        ->and($r['training']['avg_rating'])->toBe(4.0)
        ->and($r['training']['days'])->toHaveCount(7)
        ->and($r['training']['days'][0]['status'])->toBe('completed')
        ->and($r['training']['days'][2]['status'])->toBe('missed')
        ->and($r['period']['label'])->toBe('12 - 18 Oct 2026')
        ->and($r['period']['complete'])->toBeFalse();
});

test('records, posture, xp and badges are limited to the week', function () {
    $user = rUser();
    $bench = Exercise::where('slug', 'like', '%bench%')->firstOrFail();
    PersonalRecord::create(['user_id' => $user->id, 'exercise_id' => $bench->id, 'type' => 'est_1rm', 'value' => 100, 'previous_value' => 95, 'weight_kg' => 85, 'reps' => 5, 'achieved_at' => '2026-10-14 10:00:00']);
    $squat = Exercise::where('slug', 'like', '%squat%')->firstOrFail();
    PersonalRecord::create(['user_id' => $user->id, 'exercise_id' => $squat->id, 'type' => 'est_1rm', 'value' => 140, 'achieved_at' => '2026-10-05 10:00:00']); // last week

    PostureSession::factory()->create(['user_id' => $user->id, 'exercise_id' => $bench->id, 'started_at' => '2026-10-13 09:00:00', 'form_score_avg' => 90, 'total_reps' => 10, 'good_reps' => 9]);
    PostureSession::factory()->create(['user_id' => $user->id, 'exercise_id' => $bench->id, 'started_at' => '2026-10-13 09:30:00', 'form_score_avg' => 70, 'total_reps' => 8, 'good_reps' => 5]);

    app(XpService::class)->award($user, 'workout', 50, 'Workout', 'test', 1);
    $user->userBadges()->create(['badge_id' => Badge::firstOrFail()->id, 'awarded_at' => '2026-10-14 10:00:00']);

    $r = app(WeeklyReportService::class)->build($user->refresh(), monday());

    expect($r['records'])->toHaveCount(1)->and($r['records'][0]['exercise'])->toBe($bench->name)->and($r['records'][0]['previous'])->toBe(95.0)
        ->and($r['posture'])->toMatchArray(['sessions' => 2, 'avg_score' => 80, 'good_reps' => 14, 'total_reps' => 18])
        ->and($r['posture']['best']['score'])->toBe(90)
        ->and($r['xp']['earned'])->toBe(50)
        ->and($r['badges'])->toHaveCount(1);
});

test('nutrition, water, creatine, sleep and body sections use the days logged', function () {
    $user = rUser(['weight_kg' => 80]);
    foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $d) {
        FoodLog::create(['user_id' => $user->id, 'logged_on' => $d, 'meal' => 'lunch', 'name_snapshot' => 'Chicken', 'quantity' => 1, 'unit' => 'serving', 'grams' => 200, 'kcal' => 2400, 'protein_g' => 160, 'carbs_g' => 200, 'fat_g' => 70, 'fibre_g' => 20]);
    }
    WaterLog::create(['user_id' => $user->id, 'logged_on' => '2026-10-12', 'amount_ml' => 5000, 'logged_at' => '2026-10-12 12:00:00']);
    DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-12', 'creatine_taken' => true, 'sleep_hours' => 8]);
    DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-13', 'creatine_taken' => true, 'sleep_hours' => 6]);
    Measurement::create(['user_id' => $user->id, 'measured_on' => '2026-10-08', 'weight_kg' => 81]);
    Measurement::create(['user_id' => $user->id, 'measured_on' => '2026-10-15', 'weight_kg' => 80.2]);

    $r = app(WeeklyReportService::class)->build($user, monday());

    expect($r['nutrition']['days_logged'])->toBe(3)->and($r['nutrition']['avg_kcal'])->toBe(2400)->and($r['nutrition']['avg_protein_g'])->toBe(160)
        ->and($r['water']['days_hit'])->toBe(1)->and($r['water']['days_tracked'])->toBe(5)
        ->and($r['creatine']['days_taken'])->toBe(2)
        ->and($r['sleep']['nights_logged'])->toBe(2)->and($r['sleep']['avg_hours'])->toBe(7.0)
        ->and($r['body']['weight_kg'])->toBe(80.2)->and($r['body']['change_kg'])->toBe(-0.8);
});

test('a finished week is stored once and reused, while the running week is always rebuilt', function () {
    $user = rUser();
    $svc = app(WeeklyReportService::class);

    $first = $svc->forWeek($user, monday('2026-10-05'));
    doneSession($user, '2026-10-06');
    $again = $svc->forWeek($user, monday('2026-10-05'));
    expect($again->id)->toBe($first->id)->and($again->payload['training']['completed'])->toBe(0);   // snapshot kept

    $svc->forWeek($user, monday('2026-10-05'), refresh: true);
    expect(WeeklyReport::first()->payload['training']['completed'])->toBe(1);

    $current = $svc->forWeek($user, monday());
    doneSession($user, '2026-10-12');
    expect($svc->forWeek($user, monday())->payload['training']['completed'])->toBe(1)->and($current->payload['training']['completed'])->toBe(0);
    expect(WeeklyReport::count())->toBe(2);
});

test('shareable strips private sections and the focus list', function () {
    $user = rUser();
    $svc = app(WeeklyReportService::class);
    $payload = $svc->build($user, monday());

    $safe = $svc->shareable($payload);

    foreach (['body', 'nutrition', 'water', 'creatine', 'sleep', 'focus'] as $key) {
        expect($safe)->not->toHaveKey($key);
    }
    expect($safe)->toHaveKeys(['training', 'records', 'posture', 'xp', 'badges', 'highlights']);
});

test('a guest without a public name is shown anonymously, others by first name or public name', function () {
    $svc = app(WeeklyReportService::class);
    $guest = User::factory()->guest()->onboarded()->create();
    $named = User::factory()->onboarded()->create(['name' => 'Mujtaba Ahssan'])->load('profile');
    $public = User::factory()->onboarded(['public_name' => 'IronMuj'])->create(['name' => 'Mujtaba Ahssan'])->load('profile');

    expect($svc->build($guest->load('profile'), monday())['name'])->toBe('An IronPulse athlete')
        ->and($svc->build($named, monday())['name'])->toBe('Mujtaba')
        ->and($svc->build($public, monday())['name'])->toBe('IronMuj');
});

// ---------------------------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------------------------

test('report pages render for the current and previous weeks', function () {
    $user = rUser();
    doneSession($user, '2026-10-12');

    $this->actingAs($user)->get('/reports')->assertOk()->assertSee('This week')->assertSee('12 - 18 Oct 2026');
    $this->actingAs($user)->get('/reports/2026-10-12')->assertOk()->assertSee('Training')->assertSee('Share this report');
    $this->actingAs($user)->get('/reports/2026-10-05')->assertOk();
});

test('invalid weeks are not found', function (string $week) {
    $user = rUser();

    $this->actingAs($user)->get("/reports/{$week}")->assertNotFound();
})->with([
    'not a monday' => '2026-10-13',
    'future week' => '2026-10-19',
    'impossible date' => '2026-02-30',
    'too old' => '2020-01-06',
]);

test('reports require login', function () {
    $this->get('/reports')->assertRedirect('/login');
    $this->get('/reports/2026-10-12')->assertRedirect('/login');
    $this->get('/reports/2026-10-12/pdf')->assertRedirect('/login');
});

test('the pdf is a real pdf with a sensible filename', function () {
    $user = rUser();
    doneSession($user, '2026-10-12');

    $res = $this->actingAs($user)->get('/reports/2026-10-12/pdf')->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($res->headers->get('Content-Disposition'))->toContain('ironpulse-week-2026-10-12.pdf')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr($res->getContent(), 0, 5))->toBe('%PDF-');
});

// ---------------------------------------------------------------------------------------------
// Share links
// ---------------------------------------------------------------------------------------------

test('a share link opens without login and shows training but not weight, food or sleep', function () {
    $user = rUser(['public_name' => 'IronMuj']);
    doneSession($user, '2026-10-12');
    Measurement::create(['user_id' => $user->id, 'measured_on' => '2026-10-13', 'weight_kg' => 83.7]);
    DailyLog::create(['user_id' => $user->id, 'log_date' => '2026-10-13', 'sleep_hours' => 5.5]);

    $this->actingAs($user)->post('/reports/2026-10-12/share', ['days' => 14])->assertRedirect('/reports/2026-10-12');
    $token = WeeklyReport::firstOrFail()->share_token;
    expect($token)->toHaveLength(32);

    auth()->logout();
    $res = $this->get("/r/{$token}")->assertOk()->assertSee("IronMuj's week")->assertSee('Training');

    $res->assertDontSee('83.7')->assertDontSee('Food, water, sleep and body')->assertDontSee('Focus for next week')->assertDontSee($user->email);
    expect($res->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($res->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');
    $res->assertSee('name="robots" content="noindex, nofollow"', false);
});

test('share links expire, can be revoked, and cannot be guessed', function () {
    $user = rUser();
    $this->actingAs($user)->post('/reports/2026-10-12/share', ['days' => 7]);
    $report = WeeklyReport::firstOrFail();
    $token = $report->share_token;
    auth()->logout();

    $this->get("/r/{$token}")->assertOk();
    $this->get('/r/'.Str::random(32))->assertNotFound();
    $this->get('/r/short')->assertNotFound();

    Carbon::setTestNow('2026-10-24 12:00:00');
    $this->get("/r/{$token}")->assertNotFound();

    Carbon::setTestNow('2026-10-16 12:00:00');
    $this->actingAs($user)->delete('/reports/2026-10-12/share')->assertRedirect();
    auth()->logout();
    expect($report->fresh()->share_token)->toBeNull();
    $this->get("/r/{$token}")->assertNotFound();
});

test('sharing validates the lifetime and only the owner can share their report', function () {
    $user = rUser();
    $this->actingAs($user)->post('/reports/2026-10-12/share', ['days' => 365])->assertSessionHasErrors('days');
    $this->actingAs($user)->post('/reports/2026-10-12/share', [])->assertSessionHasErrors('days');

    $this->actingAs($user)->post('/reports/2026-10-12/share', ['days' => 7]);
    $other = rUser();
    $this->actingAs($other)->post('/reports/2026-10-12/share', ['days' => 7]);

    expect(WeeklyReport::count())->toBe(2)->and(WeeklyReport::pluck('share_token')->unique()->count())->toBe(2);
    foreach (WeeklyReport::all() as $row) {
        expect($row->user_id)->toBeIn([$user->id, $other->id]);
    }
});
