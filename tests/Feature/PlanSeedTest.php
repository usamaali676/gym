<?php

use App\Models\Exercise;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => seedPlan());

test('the seed matches the PDF plan', function () {
    expect(Plan::count())->toBe(1)
        ->and(PlanDay::count())->toBe(7)
        ->and(PlanDay::where('is_rest', true)->pluck('name')->all())->toBe(['Saturday', 'Sunday'])
        ->and(Exercise::count())->toBe(31)
        ->and(PlanDayExercise::count())->toBe(35);

    $counts = PlanDay::orderBy('day_of_week')->withCount('items')->pluck('items_count')->all();
    expect($counts)->toBe([7, 7, 6, 8, 7, 0, 0]);
});

test('every plan row is 3 sets with the PDF rep range', function () {
    expect(PlanDayExercise::where('sets', '!=', 3)->count())->toBe(0);

    $bench = PlanDayExercise::whereHas('exercise', fn ($q) => $q->where('slug', 'flat-barbell-bench-press'))->firstOrFail();
    expect([$bench->reps_min, $bench->reps_max, $bench->focus])->toBe([6, 10, 'Overall chest mass']);
});

test('shared exercises are one row linked to several days', function () {
    foreach (['seated-db-overhead-press', 'side-lateral-raise', 'seated-cable-row', 'lat-pulldown'] as $slug) {
        $exercise = Exercise::where('slug', $slug)->withCount('planItems')->firstOrFail();
        expect($exercise->plan_items_count)->toBe(2, $slug);
    }

    expect(Exercise::where('slug', 'lat-pulldown')->count())->toBe(1);
});

test('per-day differences live on the link row', function () {
    $row = fn (string $slug, int $dow) => PlanDayExercise::whereHas('exercise', fn ($q) => $q->where('slug', $slug))
        ->whereHas('day', fn ($q) => $q->where('day_of_week', $dow))->firstOrFail();

    expect($row('seated-cable-row', 2)->repsLabel())->toBe('8-12')
        ->and($row('seated-cable-row', 5)->repsLabel())->toBe('10-12')
        ->and($row('seated-db-overhead-press', 1)->focus)->toBe('Front + side delts')
        ->and($row('seated-db-overhead-press', 4)->focus)->toBe('Shoulder mass')
        ->and($row('lat-pulldown', 5)->displayName())->toBe('Lat Pulldown (Wide Grip)')
        ->and($row('lat-pulldown', 2)->displayName())->toBe('Lat Pulldown');
});

test('rest times follow the compound and isolation rule', function () {
    $compounds = Exercise::where('mechanic', 'compound')->pluck('slug')->sort()->values()->all();

    expect($compounds)->toBe(collect([
        'flat-barbell-bench-press', 'incline-dumbbell-press', 'incline-bench-press', 'seated-db-overhead-press',
        'arnold-press', 'barbell-row', 'seated-cable-row', 'single-arm-dumbbell-row', 'barbell-squat', 'leg-press',
        'romanian-deadlift', 'pull-ups-assisted-pull-ups',
    ])->sort()->values()->all());

    $squat = Exercise::where('slug', 'barbell-squat')->firstOrFail();
    $curl = Exercise::where('slug', 'barbell-curl')->firstOrFail();
    expect([$squat->rest_min_seconds, $squat->rest_max_seconds])->toBe([120, 180])
        ->and([$curl->rest_min_seconds, $curl->rest_max_seconds])->toBe([60, 90]);
});

test('the plan rules are stored as data', function () {
    $rules = Plan::default()->rules;
    expect($rules['creatine']['grams'])->toBe(5)
        ->and($rules['sleep']['note'])->toBe('Muscle is built during recovery, not in the gym.')
        ->and($rules['progressive_overload']['add_weight_kg'])->toBe([2.5, 5]);
});

test('seeding twice does not duplicate anything', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::count())->toBe(1)->and(PlanDay::count())->toBe(7)->and(Exercise::count())->toBe(31)->and(PlanDayExercise::count())->toBe(35);
});
