<?php

use App\Models\Badge;
use App\Models\Exercise;
use App\Models\Food;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\XpEvent;
use App\Services\BadgeMetrics;
use App\Services\BadgeService;
use App\Services\DietPlanService;
use App\Services\LeaderboardService;
use App\Services\XpService;
use Database\Factories\ProfileFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPosture();
    seedFoods();
    seedBadges();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday
});
afterEach(fn () => Carbon::setTestNow());

function gUser(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create()->load('profile');
}

function earned(User $user): array
{
    return UserBadge::where('user_id', $user->id)->with('badge')->get()->pluck('badge.slug')->all();
}

test('the catalogue is sound: unique slugs, known metrics, positive thresholds and rewards', function () {
    $badges = Badge::all();

    expect($badges->count())->toBeGreaterThanOrEqual(30)->and($badges->pluck('slug')->unique())->toHaveCount($badges->count());
    foreach ($badges as $b) {
        expect(BadgeMetrics::names())->toContain($b->criteria['metric'])
            ->and($b->criteria['gte'])->toBeGreaterThan(0)
            ->and($b->xp_reward)->toBeGreaterThan(0)
            ->and(['bronze', 'silver', 'gold', 'special'])->toContain($b->tier);
    }
    foreach (['first-workout', 'streak-7', 'perfect-reps-100', 'posture-master', 'hydration-hero', 'creatine-30'] as $slug) {
        expect($badges->pluck('slug'))->toContain($slug);
    }
});

test('seeding badges twice keeps one row per slug', function () {
    $count = Badge::count();
    seedBadges();

    expect(Badge::count())->toBe($count);
});

test('finishing a workout awards the first-workout badge once, with xp', function () {
    $user = gUser();
    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12'])->assertRedirect();

    expect(earned($user))->toContain('first-workout');
    $row = UserBadge::where('user_id', $user->id)->firstOrFail();
    expect($row->context)->toMatchArray(['source' => 'workout'])->and($row->awarded_at)->not->toBeNull();
    $badgeXp = (int) XpEvent::where(['user_id' => $user->id, 'type' => 'badge'])->sum('amount'); // MySQL returns SUM() as a string
    expect($badgeXp)->toBe(25);

    // a second evaluation changes nothing
    $again = app(BadgeService::class)->evaluate($user->refresh());
    expect(collect($again)->pluck('slug'))->not->toContain('first-workout')
        ->and(UserBadge::where('user_id', $user->id)->count())->toBe(count(earned($user)));
});

test('evaluation is limited to the categories an event could change', function () {
    $user = gUser();
    $user->forceFill(['xp_total' => 2600, 'level' => 6, 'longest_workout_streak' => 8])->save();

    $training = collect(app(BadgeService::class)->evaluate($user, ['training']))->pluck('slug');
    expect($training)->toContain('streak-3', 'streak-7')->not->toContain('level-5');

    $level = collect(app(BadgeService::class)->evaluate($user, ['level']))->pluck('slug');
    expect($level)->toContain('level-5');
});

test('a posture set earns posture badges and the api reports them', function () {
    $user = gUser();
    $exercise = Exercise::where('slug', 'barbell-squat')->with('postureRules')->firstOrFail();
    $rule = $exercise->activePostureRule();

    $res = $this->actingAs($user)->postJson('/api/posture-sessions', [
        'client_uuid' => (string) Str::uuid(), 'exercise_id' => $exercise->id, 'posture_rule_id' => $rule->id,
        'rule_version' => 1, 'camera_view' => 'side', 'started_at' => now()->subMinute()->toIso8601String(), 'duration_seconds' => 60,
        'total_reps' => 100, 'good_reps' => 100, 'bad_reps' => 0, 'form_score_avg' => 95, 'form_score_min' => 90, 'error_counts' => [], 'rep_log' => [],
    ])->assertCreated();

    expect(collect($res->json('badges'))->pluck('slug'))->toContain('posture-first', 'perfect-reps-100')
        ->and($res->json('badges.0'))->toHaveKeys(['slug', 'name', 'icon', 'tier', 'xp_reward']);
});

test('food badges: seven different days of logging', function () {
    $user = gUser();
    $banana = Food::where('name', 'Banana')->firstOrFail();
    foreach (range(0, 6) as $i) {
        $date = Carbon::parse('2026-10-14')->subDays($i)->toDateString();
        $res = $this->actingAs($user)->postJson('/api/food-logs', ['food_id' => $banana->id, 'logged_on' => $date, 'meal' => 'snack', 'quantity' => 100, 'unit' => 'grams'])->assertCreated();
        if ($i < 6) {
            expect(collect($res->json('badges'))->pluck('slug'))->not->toContain('food-week');
        }
    }

    expect(earned($user))->toContain('food-week');
});

test('protein pro needs the protein target on seven days', function () {
    $user = gUser(['goal' => 'maintenance']);
    $plan = app(DietPlanService::class)->build($user);
    $whey = Food::where('name', 'Whey protein powder')->firstOrFail();
    $grams = (int) ceil($plan->protein_g / 0.8) + 5; // whey is 80 g protein per 100 g

    foreach (range(0, 6) as $i) {
        $this->actingAs($user)->postJson('/api/food-logs', ['food_id' => $whey->id, 'logged_on' => Carbon::parse('2026-10-14')->subDays($i)->toDateString(), 'meal' => 'snack', 'quantity' => $grams, 'unit' => 'grams'])->assertCreated();
    }

    expect(earned($user))->toContain('protein-pro');
});

test('the badges page shows earned badges, progress towards the rest, and the xp reward', function () {
    $user = gUser();
    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);

    $this->actingAs($user)->get('/badges')->assertOk()
        ->assertSee('Badges and leaderboard')
        ->assertSee('First Workout')->assertSee('Earned')
        ->assertSee('Getting Serious')->assertSee('/ 10 · +25 XP', false)
        ->assertSee('Posture Master');
});

test('overview computes progress without earning anything', function () {
    $user = gUser();
    $user->forceFill(['longest_workout_streak' => 5])->save();

    $rows = app(BadgeService::class)->overview($user)->keyBy(fn ($r) => $r['badge']->slug);

    expect($rows['streak-7']['earned'])->toBeFalse()->and($rows['streak-7']['value'])->toEqual(5.0)->and($rows['streak-7']['pct'])->toBe(71)
        ->and(UserBadge::count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Leaderboard
// ---------------------------------------------------------------------------------------------

test('the leaderboard lists only opted-in members, by weekly xp, under their public name', function () {
    $a = gUser(['leaderboard_opt_in' => true, 'public_name' => 'IronAnna']);
    $b = gUser(['leaderboard_opt_in' => true, 'public_name' => null]);
    $private = gUser(['leaderboard_opt_in' => false]);
    $guest = User::factory()->guest()->has(ProfileFactory::new()->state(['leaderboard_opt_in' => true]), 'profile')->create(['onboarded_at' => now()]);

    $xp = app(XpService::class);
    $xp->award($a, 'set_complete', 100, 'x', 't', 1);
    $xp->award($b, 'set_complete', 150, 'x', 't', 2);
    $xp->award($private, 'set_complete', 900, 'x', 't', 3);
    $xp->award($guest, 'set_complete', 900, 'x', 't', 4);
    // last week does not count
    XpEvent::create(['user_id' => $a->id, 'type' => 'set_complete', 'amount' => 5000, 'source_type' => 't', 'source_id' => 5, 'description' => 'old', 'created_at' => now()->subWeeks(2)]);

    $page = $this->actingAs($a)->get('/badges')->assertOk()->assertSee('IronAnna')->assertSee($b->name)->assertDontSee($private->name)->assertDontSee('Guest');
    $board = app(LeaderboardService::class)->weekly($a, now());

    expect($board->pluck('name')->all())->toBe([$b->name, 'IronAnna'])
        ->and($board->pluck('xp')->all())->toBe([150, 100])
        ->and($board->firstWhere('name', 'IronAnna')['me'])->toBeTrue();
});

test('the leaderboard never leaks emails or ids', function () {
    $a = gUser(['leaderboard_opt_in' => true, 'public_name' => 'Anna']);
    app(XpService::class)->award($a, 'set_complete', 10, 'x', 't', 1);

    $html = $this->actingAs($a)->get('/badges')->getContent();

    expect($html)->not->toContain($a->email);
});
