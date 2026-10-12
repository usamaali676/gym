<?php

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\PlanDayExercise;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\OneRepMaxService;
use App\Services\PersonalRecordService;
use App\Services\ProgressiveOverloadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedLibrary();
    Carbon::setTestNow('2026-10-14 12:00:00');
});
afterEach(fn () => Carbon::setTestNow());

/** A completed past session with the given sets for one exercise: [[weight, reps], ...]. */
function pastSession(User $user, string $slug, string $date, array $sets, int $dow = 1): WorkoutSession
{
    $exercise = Exercise::where('slug', $slug)->firstOrFail();
    $session = WorkoutSession::create([
        'user_id' => $user->id, 'scheduled_for' => $date, 'status' => 'completed', 'client_uuid' => (string) Str::uuid(),
        'plan_day_id' => $user->profile->plan->days()->where('day_of_week', $dow)->value('id'),
    ]);
    $row = SessionExercise::create([
        'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => 1,
        'target_sets' => 3, 'target_reps_min' => 6, 'target_reps_max' => 10, 'status' => 'done',
    ]);
    foreach ($sets as $i => [$weight, $reps]) {
        SessionSet::create([
            'session_exercise_id' => $row->id, 'workout_session_id' => $session->id, 'exercise_id' => $exercise->id,
            'set_number' => $i + 1, 'weight_kg' => $weight, 'reps' => $reps, 'completed_at' => $date.' 10:00:00',
        ]);
    }

    return $session;
}

function planRowFor(string $slug, int $dow): PlanDayExercise
{
    return PlanDayExercise::whereHas('exercise', fn ($q) => $q->where('slug', $slug))
        ->whereHas('day', fn ($q) => $q->where('day_of_week', $dow))->firstOrFail();
}

test('one rep max uses Epley and caps the rep count', function () {
    $orm = app(OneRepMaxService::class);

    expect($orm->epley(100, 1))->toBe(100.0)
        ->and(round($orm->epley(100, 10), 2))->toBe(133.33)
        ->and($orm->estimate(80, 5))->toBe(93.33)
        ->and($orm->epley(60, 30))->toBe($orm->epley(60, 12))     // reps beyond 12 are capped
        ->and($orm->epley(0, 8))->toBe(0.0)
        ->and($orm->epley(50, 0))->toBe(0.0)
        ->and(round($orm->brzycki(100, 10), 1))->toBe(133.3);
});

test('weight for reps inverts the estimate and the table is rounded to 2.5 kg', function () {
    $orm = app(OneRepMaxService::class);

    expect(round($orm->weightForReps(133.33, 10), 1))->toBe(100.0);
    $table = $orm->table(100);
    expect($table[0])->toBe(['percent' => 100, 'weight' => 100.0, 'reps' => 1])
        ->and(collect($table)->every(fn ($row) => fmod($row['weight'], 2.5) === 0.0))->toBeTrue();
});

test('there is no suggestion without history', function () {
    $user = User::factory()->onboarded()->create();
    $bench = planRowFor('flat-barbell-bench-press', 1);

    expect(app(ProgressiveOverloadService::class)->suggest($user, $bench, $bench->exercise))->toBeNull();
});

test('hitting the top of the range on every set adds weight (2.5 kg upper body)', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 10], [60, 10], [60, 10]]);
    $bench = planRowFor('flat-barbell-bench-press', 1);

    $s = app(ProgressiveOverloadService::class)->suggest($user, $bench, $bench->exercise);

    expect($s['weight_kg'])->toBe(62.5)->and($s['reps'])->toBe(6)->and($s['reason'])->toContain('add 2.5 kg');
});

test('lower body lifts jump by 5 kg', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'barbell-squat', '2026-10-07', [[100, 10], [100, 10], [100, 10]], 3);
    $squat = planRowFor('barbell-squat', 3);

    expect(app(ProgressiveOverloadService::class)->suggest($user, $squat, $squat->exercise)['weight_kg'])->toBe(105.0);
});

test('within the range the target is one more rep at the same weight', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 8], [60, 7], [60, 7]]);
    $bench = planRowFor('flat-barbell-bench-press', 1);

    $s = app(ProgressiveOverloadService::class)->suggest($user, $bench, $bench->exercise);

    expect($s['weight_kg'])->toBe(60.0)->and($s['reps'])->toBe(8);
});

test('missing the bottom of the range keeps the weight and asks for the minimum reps', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[70, 6], [70, 5], [70, 4]]);
    $bench = planRowFor('flat-barbell-bench-press', 1);

    $s = app(ProgressiveOverloadService::class)->suggest($user, $bench, $bench->exercise);

    expect($s['weight_kg'])->toBe(70.0)->and($s['reps'])->toBe(6)->and($s['reason'])->toContain('before adding weight');
});

test('fewer sets than planned does not trigger a weight increase', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 10], [60, 10]]);
    $bench = planRowFor('flat-barbell-bench-press', 1);

    expect(app(ProgressiveOverloadService::class)->suggest($user, $bench, $bench->exercise)['weight_kg'])->toBe(60.0);
});

test('history is shared between the days a lift appears on, but each day keeps its own rep range', function () {
    $user = User::factory()->onboarded()->create();
    // Seated cable row: 8-12 on Tuesday, 10-12 on Friday. Trained on Tuesday at 8-8-8.
    pastSession($user, 'seated-cable-row', '2026-10-06', [[50, 8], [50, 8], [50, 8]], 2);
    $service = app(ProgressiveOverloadService::class);
    $tuesday = planRowFor('seated-cable-row', 2);
    $friday = planRowFor('seated-cable-row', 5);

    $tue = $service->suggest($user, $tuesday, $tuesday->exercise);
    $fri = $service->suggest($user, $friday, $friday->exercise);

    expect($tue['reps'])->toBe(9)                            // in range: +1
        ->and($fri['reps'])->toBe(10)                        // below Friday's 10-12 range: build up to 10
        ->and($fri['weight_kg'])->toBe(50.0);
});

test('the most recent session decides, not the heaviest', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'flat-barbell-bench-press', '2026-09-28', [[80, 6], [80, 6], [80, 6]]);
    pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 9], [60, 9], [60, 9]]);
    $bench = planRowFor('flat-barbell-bench-press', 1);

    expect(app(ProgressiveOverloadService::class)->suggest($user, $bench, $bench->exercise)['weight_kg'])->toBe(60.0);
});

test('bodyweight work is progressed by reps', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'pull-ups-assisted-pull-ups', '2026-10-02', [[null, 10], [null, 10], [null, 10]], 5);
    $row = planRowFor('pull-ups-assisted-pull-ups', 5);

    $s = app(ProgressiveOverloadService::class)->suggest($user, $row, $row->exercise);

    expect($s['weight_kg'])->toBeNull()->and($s['reps'])->toBe(10)->and($s['reason'])->toContain('Add weight');
});

test('weight targets for a day come back keyed by plan row', function () {
    $user = User::factory()->onboarded()->create();
    pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 10], [60, 10], [60, 10]]);
    $monday = $user->profile->plan->days()->where('day_of_week', 1)->firstOrFail();

    $targets = app(ProgressiveOverloadService::class)->weightTargetsForDay($user, $monday);

    expect($targets[planRowFor('flat-barbell-bench-press', 1)->id])->toBe(62.5)
        ->and($targets[planRowFor('skull-crushers', 1)->id])->toBeNull();
});

test('a first result is a baseline, not a personal record', function () {
    $user = User::factory()->onboarded()->create();
    $session = pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 8]]);
    $set = $session->sets()->first();

    expect(app(PersonalRecordService::class)->evaluate($user, $set))->toBe([])
        ->and(PersonalRecord::where('user_id', $user->id)->pluck('type')->sort()->values()->all())->toBe(['best_set_volume', 'est_1rm', 'max_weight']);
});

test('beating a previous session is a PR and the record moves', function () {
    $user = User::factory()->onboarded()->create();
    $service = app(PersonalRecordService::class);
    $first = pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 8]]);
    $service->evaluate($user, $first->sets()->first());

    $second = pastSession($user, 'flat-barbell-bench-press', '2026-10-12', [[65, 8]]);
    $broken = $service->evaluate($user, $second->sets()->first());

    expect(collect($broken)->pluck('type')->all())->toContain('est_1rm', 'max_weight')
        ->and(PersonalRecord::where(['user_id' => $user->id, 'type' => 'max_weight'])->value('value'))->toBe(65.0);
});

test('a lighter or equal set is not a PR', function () {
    $user = User::factory()->onboarded()->create();
    $service = app(PersonalRecordService::class);
    $service->evaluate($user, pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 8]])->sets()->first());

    $same = pastSession($user, 'flat-barbell-bench-press', '2026-10-12', [[60, 8]]);

    expect($service->evaluate($user, $same->sets()->first()))->toBe([]);
});

test('a pyramid inside one session only counts against previous sessions', function () {
    $user = User::factory()->onboarded()->create();
    $service = app(PersonalRecordService::class);
    $service->evaluate($user, pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[60, 8]])->sets()->first());

    $today = pastSession($user, 'flat-barbell-bench-press', '2026-10-12', [[62.5, 8], [65, 8], [60, 8]]);
    $results = $today->sets()->orderBy('set_number')->get()->map(fn ($s) => $service->evaluate($user, $s) !== [])->all();

    expect($results)->toBe([true, true, false]);   // 62.5 and 65 beat last week's 60; the 60 does not
    expect(PersonalRecord::where(['user_id' => $user->id, 'type' => 'max_weight'])->value('value'))->toBe(65.0);
});

test('bodyweight sets track max reps', function () {
    $user = User::factory()->onboarded()->create();
    $service = app(PersonalRecordService::class);
    $service->evaluate($user, pastSession($user, 'pull-ups-assisted-pull-ups', '2026-10-02', [[null, 6]], 5)->sets()->first());

    $broken = $service->evaluate($user, pastSession($user, 'pull-ups-assisted-pull-ups', '2026-10-09', [[null, 8]], 5)->sets()->first());

    expect($broken[0]['type'])->toBe('max_reps')->and($broken[0]['value'])->toBe(8.0);
});

test('warm-up sets never set records', function () {
    $user = User::factory()->onboarded()->create();
    $session = pastSession($user, 'flat-barbell-bench-press', '2026-10-05', [[100, 5]]);
    $set = $session->sets()->first();
    $set->update(['is_warmup' => true]);

    expect(app(PersonalRecordService::class)->evaluate($user, $set))->toBe([])
        ->and(PersonalRecord::count())->toBe(0);
});
