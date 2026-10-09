<?php

use App\Models\DailyLog;
use App\Models\PlanDay;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\WaterLog;
use App\Models\XpEvent;
use App\Services\DailyLogService;
use App\Services\RoutineService;
use App\Services\WaterService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    seedBadges();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday: a training day
});
afterEach(fn () => Carbon::setTestNow());

function hUser(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create()->load('profile');
}

// ---------------------------------------------------------------------------------------------
// Water
// ---------------------------------------------------------------------------------------------

test('water goal is 35 ml per kg plus 500 ml on training days, or the users own number', function () {
    $service = app(WaterService::class);
    $user = hUser(['weight_kg' => 80]);

    expect($service->goalMl($user, Carbon::parse('2026-10-14')))->toBe(3300)    // Wed: training day
        ->and($service->goalMl($user, Carbon::parse('2026-10-17')))->toBe(2800); // Sat: rest

    $user->profile->update(['water_goal_ml' => 4000]);
    expect($service->goalMl($user->fresh('profile'), Carbon::parse('2026-10-17')))->toBe(4000);
});

test('adding water updates the total, and reaching the goal pays xp exactly once', function () {
    $user = hUser(['weight_kg' => 80, 'water_goal_ml' => 1000]);

    $a = $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 500])->assertOk();
    expect($a->json('water.total_ml'))->toBe(500)->and($a->json('water.reached'))->toBeFalse()->and($a->json('water.xp'))->toBe(0);

    $b = $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 500])->assertOk();
    expect($b->json('water.reached'))->toBeTrue()->and($b->json('water.xp'))->toBe(10)->and($b->json('water.pct'))->toBe(100);

    $c = $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 250])->assertOk();
    expect($c->json('water.xp'))->toBe(0)->and(XpEvent::where('user_id', $user->id)->where('type', 'water_goal')->count())->toBe(1);
});

test('undo removes the latest drink', function () {
    $user = hUser(['water_goal_ml' => 2000]);
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 250]);
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 500]);

    $res = $this->actingAs($user)->deleteJson('/api/water/last')->assertOk();

    expect($res->json('water.total_ml'))->toBe(250)->and(WaterLog::count())->toBe(1);
});

test('water amounts and dates are validated and capped', function () {
    $user = hUser();

    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 0])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 5000])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 250, 'date' => '2026-10-20'])->assertStatus(422);   // future
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 250, 'date' => '2026-09-01'])->assertStatus(422);   // too old
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 250, 'date' => '2026-10-10'])->assertOk();          // within a week

    foreach (range(1, 8) as $i) {
        $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 2000, 'date' => '2026-10-13']);
    }
    expect(WaterLog::whereDate('logged_on', '2026-10-13')->sum('amount_ml'))->toBeLessThanOrEqual(WaterService::MAX_DAY_ML);
});

test('water is private to each user', function () {
    $a = hUser();
    $b = hUser();
    $this->actingAs($a)->postJson('/api/water', ['amount_ml' => 500]);

    expect($this->actingAs($b)->getJson('/api/habits')->json('water.total_ml'))->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Creatine
// ---------------------------------------------------------------------------------------------

test('creatine can be ticked and unticked, and pays xp once per day', function () {
    $user = hUser();

    $on = $this->actingAs($user)->putJson('/api/creatine', ['taken' => true])->assertOk();
    expect($on->json('creatine.taken'))->toBeTrue()->and($on->json('creatine.current'))->toBe(1)->and($on->json('creatine.dose_g'))->toEqual(5);

    $this->actingAs($user)->putJson('/api/creatine', ['taken' => false]);
    $this->actingAs($user)->putJson('/api/creatine', ['taken' => true]);

    expect(XpEvent::where('user_id', $user->id)->where('type', 'creatine')->count())->toBe(1)
        ->and(DailyLog::where('user_id', $user->id)->count())->toBe(1);
});

test('the creatine streak survives an untaken today and breaks after a missed day', function () {
    $user = hUser();
    $service = app(DailyLogService::class);
    foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'] as $d) {
        $service->setCreatine($user, Carbon::parse($d), true);
    }

    // today (14th) not ticked yet: the streak still counts back from yesterday
    expect($service->creatineStats($user, Carbon::parse('2026-10-14')))->toBe(['current' => 4, 'longest' => 4, 'taken_days' => 4]);

    $service->setCreatine($user, Carbon::parse('2026-10-14'), true);
    expect($service->creatineStats($user, Carbon::parse('2026-10-14'))['current'])->toBe(5);

    // a gap on the 16th, taken on the 17th: streak restarts, longest is kept
    $service->setCreatine($user, Carbon::parse('2026-10-17'), true);
    $stats = $service->creatineStats($user, Carbon::parse('2026-10-17'));
    expect($stats['current'])->toBe(1)->and($stats['longest'])->toBe(5);
    expect($service->creatineStats($user, Carbon::parse('2026-10-19'))['current'])->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Sleep and mobility
// ---------------------------------------------------------------------------------------------

test('sleep is saved, averaged, and meeting the goal pays xp once', function () {
    $user = hUser(['sleep_goal_hours' => 7.5]);

    $short = $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 6, 'sleep_quality' => 2, 'bedtime' => '00:30', 'wake_time' => '06:30'])->assertOk();
    expect($short->json('sleep.hours'))->toEqual(6)->and($short->json('sleep.bedtime'))->toBe('00:30')
        ->and(XpEvent::where('type', 'sleep_goal')->count())->toBe(0);

    $good = $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 8])->assertOk();
    expect($good->json('sleep.hours'))->toEqual(8)->and(XpEvent::where('type', 'sleep_goal')->count())->toBe(1)
        ->and(DailyLog::where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 7, 'date' => '2026-10-13']);
    expect($this->actingAs($user)->getJson('/api/habits')->json('sleep.average'))->toEqual(7.5);
});

test('sleep input is validated', function () {
    $user = hUser();

    $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 20])->assertStatus(422);
    $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 7, 'sleep_quality' => 9])->assertStatus(422);
    $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 7, 'bedtime' => '25:99'])->assertStatus(422);
    $this->actingAs($user)->putJson('/api/sleep', [])->assertStatus(422);
});

test('mobility minutes are stored on the same daily row', function () {
    $user = hUser();
    $this->actingAs($user)->putJson('/api/creatine', ['taken' => true]);

    $res = $this->actingAs($user)->putJson('/api/mobility', ['minutes' => 12])->assertOk();

    expect($res->json('mobility_minutes'))->toBe(12)->and($res->json('creatine.taken'))->toBeTrue()->and(DailyLog::count())->toBe(1);
    $this->actingAs($user)->putJson('/api/mobility', ['minutes' => 999])->assertStatus(422);
});

test('the habit state carries 14 days of history', function () {
    $user = hUser(['water_goal_ml' => 1000]);
    $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 500, 'date' => '2026-10-13']);
    $this->actingAs($user)->putJson('/api/creatine', ['taken' => true, 'date' => '2026-10-13']);

    $history = collect($this->actingAs($user)->getJson('/api/habits')->json('history'))->keyBy('date');

    expect($history)->toHaveCount(14)->and($history['2026-10-13']['water_pct'])->toBe(50)->and($history['2026-10-13']['creatine'])->toBeTrue()
        ->and($history['2026-10-12']['creatine'])->toBeFalse();
});

test('hydration, creatine and sleep badges are earned through the habit api', function () {
    $user = hUser(['water_goal_ml' => 500, 'sleep_goal_hours' => 7]);
    $badges = [];
    foreach (range(0, 6) as $i) {
        $d = Carbon::parse('2026-10-14')->subDays($i)->toDateString();
        $badges[] = $this->actingAs($user)->postJson('/api/water', ['amount_ml' => 500, 'date' => $d])->json('badges');
        $this->actingAs($user)->putJson('/api/creatine', ['taken' => true, 'date' => $d]);
        $badges[] = $this->actingAs($user)->putJson('/api/sleep', ['sleep_hours' => 8, 'date' => $d])->json('badges');
    }

    $slugs = UserBadge::where('user_id', $user->id)->with('badge')->get()->pluck('badge.slug');
    expect($slugs)->toContain('hydration-hero', 'creatine-7', 'sleep-week');
});

// ---------------------------------------------------------------------------------------------
// Routines
// ---------------------------------------------------------------------------------------------

test('a push day warm-up is dynamic and covers the shoulders; the cool-down is static stretches for the chest and arms', function () {
    $monday = PlanDay::where('day_of_week', 1)->firstOrFail();
    $service = app(RoutineService::class);

    $warm = $service->forDay($monday, 'warmup');
    expect($warm['items'])->toHaveCount(5)->and(collect($warm['items'])->every(fn ($i) => $i['exercise']->mobility_type === 'dynamic'))->toBeTrue()
        ->and(collect($warm['items'])->pluck('exercise.slug'))->toContain('arm-circles')
        ->and($warm['minutes'])->toBeGreaterThanOrEqual(3)->and($warm['title'])->toContain('Warm-up');

    $cool = $service->forDay($monday, 'cooldown');
    expect(collect($cool['items'])->every(fn ($i) => $i['exercise']->mobility_type === 'static'))->toBeTrue()
        ->and(collect($cool['items'])->pluck('exercise.slug'))->toContain('doorway-chest-stretch');
});

test('a leg day routine is about legs, and each routine follows the plan', function () {
    $wednesday = PlanDay::where('day_of_week', 3)->firstOrFail();

    $cool = collect(app(RoutineService::class)->forDay($wednesday, 'cooldown')['items'])->pluck('exercise.slug');
    $warm = collect(app(RoutineService::class)->forDay($wednesday, 'warmup')['items'])->pluck('exercise.slug');

    expect($cool)->toContain('hamstring-stretch')->and($warm)->toContain('leg-swings')->and($cool)->not->toContain('doorway-chest-stretch');
});

test('rest days get a full-body recovery stretch routine, one per muscle group', function () {
    $saturday = PlanDay::where('day_of_week', 6)->firstOrFail();

    $routine = app(RoutineService::class)->forDay($saturday, 'warmup'); // a rest day always gets the recovery routine

    $groups = collect($routine['items'])->pluck('exercise.muscle_group.value');
    expect($routine['type'])->toBe('recovery')->and($groups->unique())->toHaveCount($groups->count())
        ->and(collect($routine['items'])->every(fn ($i) => $i['exercise']->mobility_type === 'static'))->toBeTrue()
        ->and(count($routine['items']))->toBeGreaterThanOrEqual(6);
});

test('routine minutes add up the holds, both sides and the transitions', function () {
    $routine = app(RoutineService::class)->forDay(PlanDay::where('day_of_week', 3)->firstOrFail(), 'cooldown');
    $seconds = collect($routine['items'])->sum(fn ($i) => $i['hold_seconds'] * ($i['per_side'] ? 2 : 1) + 10);

    expect($routine['minutes'])->toBe((int) max(1, round($seconds / 60)));
});

// ---------------------------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------------------------

test('the recovery page shows the habit tracker and the routine for a chosen day', function () {
    $user = hUser();

    $this->actingAs($user)->get('/recovery')->assertOk()
        ->assertSee('Recover like you train')->assertSee('Water')->assertSee('Creatine')->assertSee('Sleep')
        ->assertSee('Warm-up for Legs')
        ->assertSee('Muscle is built during recovery');

    $this->actingAs($user)->get('/recovery?day=3&type=cooldown')->assertOk()->assertSee('Cool-down for Legs')->assertSee('Hamstring Stretch');
    $this->actingAs($user)->get('/recovery?day=6')->assertOk()->assertSee('Recovery stretch routine');
    $this->actingAs($user)->get('/recovery?day=99&type=nonsense')->assertOk();
});

test('habit endpoints need a login', function () {
    $this->getJson('/api/habits')->assertUnauthorized();
    $this->postJson('/api/water', ['amount_ml' => 250])->assertUnauthorized();
    $this->get('/recovery')->assertRedirect('/login');
    $this->get('/badges')->assertRedirect('/login');
});
