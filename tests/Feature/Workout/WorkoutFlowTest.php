<?php

use App\Enums\SessionStatus;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\PlanDayExercise;
use App\Models\ProgressionTarget;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\UserExerciseSwap;
use App\Models\WorkoutSession;
use App\Models\XpEvent;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    Carbon::setTestNow('2026-10-12 09:00:00'); // Monday: push / chest
});
afterEach(fn () => Carbon::setTestNow());

function trainer(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create();
}

/** Start today's workout and return the session. */
function startToday(User $user): WorkoutSession
{
    test()->actingAs($user)->get('/workout')->assertRedirect();

    return WorkoutSession::where('user_id', $user->id)->latest('id')->firstOrFail()->load('exercises.exercise');
}

function logSet($test, User $user, WorkoutSession $session, SessionExercise $row, int $number, ?float $weight, int $reps)
{
    return $test->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/sets", [
        'session_exercise_id' => $row->id, 'set_number' => $number, 'weight_kg' => $weight, 'reps' => $reps,
    ]);
}

test('train starts todays workout and snapshots the exercises with their ranges', function () {
    $user = trainer();

    $this->actingAs($user)->get('/workout')->assertRedirect();
    $session = WorkoutSession::firstOrFail()->load('exercises.exercise');

    expect($session->status)->toBe(SessionStatus::InProgress)
        ->and($session->scheduled_for->toDateString())->toBe('2026-10-12')
        ->and($session->exercises)->toHaveCount(7)
        ->and($session->exercises->first()->exercise->slug)->toBe('flat-barbell-bench-press')
        ->and([$session->exercises->first()->target_reps_min, $session->exercises->first()->target_reps_max])->toBe([6, 10]);
    $this->get("/workout/{$session->id}")->assertOk();
});

test('starting again resumes the same session', function () {
    $user = trainer();
    $first = startToday($user);

    $this->actingAs($user)->get('/workout')->assertRedirect(route('workout.play', $first));

    expect(WorkoutSession::count())->toBe(1);
});

test('the play page boots the game with exercise content and prefs', function () {
    $user = trainer();
    $session = startToday($user);

    $response = $this->actingAs($user)->get("/workout/{$session->id}")->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('id="game-config"')
        ->and($html)->toContain('x-data="workoutGame"')
        ->and($html)->toContain('Flat Barbell Bench Press');

    preg_match('~<script type="application/json" id="game-config">(.*?)</script>~s', $html, $m);
    $config = json_decode($m[1], true);

    expect($config['exercises'])->toHaveCount(7)
        ->and($config['exercises'][0]['restSeconds'])->toBe(150)
        ->and($config['exercises'][5]['restSeconds'])->toBe(75)
        ->and($config['exercises'][0]['cues'])->not->toBeEmpty()
        ->and($config['exercises'][0]['regions']['primary'])->toContain('chest_upper')
        ->and($config['prefs']['sound'])->toBeTrue()
        ->and($config['urls']['finish'])->toContain("/api/workout-sessions/{$session->id}/finish");
});

test('a rest day sends the user to choose a day instead', function () {
    Carbon::setTestNow('2026-10-17 09:00:00'); // Saturday
    $this->actingAs(trainer())->get('/workout')->assertOk()->assertSee('Pick a workout')->assertSee('Muscle is built during recovery');
});

test('missed days can be started from the chooser', function () {
    Carbon::setTestNow('2026-10-17 09:00:00');
    $user = trainer();

    $this->actingAs($user)->get('/workout')->assertOk()->assertSee('Missed');
    $this->actingAs($user)->get('/workout?day=2&date=2026-10-13')->assertRedirect();

    expect(WorkoutSession::first()->scheduled_for->toDateString())->toBe('2026-10-13');
});

test('logging a set stores it, awards xp once and updates the session totals', function () {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->first();

    $res = logSet($this, $user, $session, $row, 1, 60, 8)->assertCreated();

    expect($res->json('set.reps'))->toBe(8)->and($res->json('isPr'))->toBeFalse()->and($res->json('xp'))->toBe(5)
        ->and($res->json('totals.sets'))->toBe(1)->and($res->json('totals.volume_kg'))->toEqual(480);

    // Re-sending the same set (retry) corrects it instead of duplicating, and pays no extra xp.
    logSet($this, $user, $session, $row, 1, 60, 9)->assertCreated();

    expect(SessionSet::count())->toBe(1)->and(SessionSet::first()->reps)->toBe(9)
        ->and(XpEvent::where('type', 'set_complete')->count())->toBe(1)
        ->and($user->refresh()->xp_total)->toBe(5)
        ->and($session->refresh()->volume_kg)->toBe(540.0);
});

test('the exercise is marked done after its planned sets', function () {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->first();

    foreach ([1, 2, 3] as $n) {
        logSet($this, $user, $session, $row, $n, 60, 8);
    }

    expect($row->refresh()->status)->toBe('done');
});

test('set input is validated', function (array $payload) {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->first();

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/sets", array_merge(['session_exercise_id' => $row->id, 'set_number' => 1], $payload))
        ->assertStatus(422);
    expect(SessionSet::count())->toBe(0);
})->with([
    'no reps or time' => [['weight_kg' => 60]],
    'negative weight' => [['weight_kg' => -5, 'reps' => 5]],
    'absurd weight' => [['weight_kg' => 5000, 'reps' => 5]],
    'too many reps' => [['reps' => 9999]],
    'set number zero' => [['set_number' => 0, 'reps' => 5]],
    'bad rpe' => [['reps' => 5, 'rpe' => 20]],
]);

test('a set cannot be logged against another sessions exercise', function () {
    $user = trainer();
    $session = startToday($user);
    $other = trainer();
    Carbon::setTestNow('2026-10-12 09:00:00');
    $otherSession = startToday($other);

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/sets", ['session_exercise_id' => $otherSession->exercises->first()->id, 'set_number' => 1, 'reps' => 5])
        ->assertNotFound();
});

test('users cannot touch each others sessions', function () {
    $owner = trainer();
    $session = startToday($owner);
    $intruder = trainer();
    $row = $session->exercises->first();

    $this->actingAs($intruder)->get("/workout/{$session->id}")->assertForbidden();
    $this->actingAs($intruder)->get("/workout/{$session->id}/summary")->assertForbidden();
    $this->actingAs($intruder)->putJson("/api/workout-sessions/{$session->id}/state", ['state' => ['idx' => 0, 'setIdx' => 0, 'phase' => 'work']])->assertForbidden();
    logSet($this, $intruder, $session, $row, 1, 60, 8)->assertForbidden();
    $this->actingAs($intruder)->postJson("/api/workout-sessions/{$session->id}/finish")->assertForbidden();
    $this->actingAs($intruder)->postJson("/api/workout-sessions/{$session->id}/abandon")->assertForbidden();
    expect(SessionSet::count())->toBe(0);
});

test('the workout api requires login', function () {
    $session = startToday(trainer());
    auth()->logout();

    $this->postJson("/api/workout-sessions/{$session->id}/sets", [])->assertUnauthorized();
});

test('resume state is autosaved, validated and kept separate from server results', function () {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->first();
    logSet($this, $user, $session, $row, 1, 60, 8);

    $state = ['idx' => 0, 'setIdx' => 1, 'phase' => 'rest', 'rest' => ['endAt' => 123, 'totalMs' => 150000, 'frozenMs' => null], 'savedAt' => 1];
    $this->actingAs($user)->putJson("/api/workout-sessions/{$session->id}/state", ['state' => $state])->assertNoContent();

    $stored = $session->refresh()->autosave_state;
    expect($stored['client']['phase'])->toBe('rest')->and($stored['xp']['set_complete'])->toBe(5);   // server-owned keys survive

    $this->actingAs($user)->putJson("/api/workout-sessions/{$session->id}/state", ['state' => ['idx' => 0]])->assertStatus(422);
    $this->actingAs($user)->putJson("/api/workout-sessions/{$session->id}/state", ['state' => array_merge($state, ['blob' => str_repeat('x', 5000)])])->assertStatus(422);

    // The page hands the saved state back to the browser so it can resume where it left off.
    preg_match('~id="game-config">(.*?)</script>~s', $this->actingAs($user)->get("/workout/{$session->id}")->getContent(), $m);
    $config = json_decode($m[1], true);
    expect($config['session']['resume']['phase'])->toBe('rest')
        ->and($config['exercises'][0]['sets'])->toHaveCount(1)
        ->and($config['exercises'][0]['sets'][0]['reps'])->toBe(8);
});

test('a set can be undone and the xp is not paid twice when it is logged again', function () {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->first();
    $id = logSet($this, $user, $session, $row, 1, 60, 8)->json('set.id');

    $this->actingAs($user)->deleteJson("/api/workout-sessions/{$session->id}/sets/{$id}")->assertOk();
    expect(SessionSet::count())->toBe(0);

    logSet($this, $user, $session, $row, 1, 60, 8)->assertCreated();

    expect($user->refresh()->xp_total)->toBe(5);
});

test('finishing needs at least one set', function () {
    $user = trainer();
    $session = startToday($user);

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertStatus(422)->assertJsonValidationErrors('session');
    expect($session->refresh()->status)->toBe(SessionStatus::InProgress);
});

test('finishing completes the session, awards xp and the streak, and builds next weeks targets', function () {
    $user = trainer();
    $session = startToday($user);
    $bench = $session->exercises->first();
    foreach ([1, 2, 3] as $n) {
        logSet($this, $user, $session, $bench, $n, 60, 10);
    }
    $incline = $session->exercises->get(1);
    logSet($this, $user, $session, $incline, 1, 22.5, 9);

    Carbon::setTestNow('2026-10-12 09:50:00');
    $res = $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish", ['active_seconds' => 2700])->assertOk();

    $session->refresh();
    expect($res->json('summary'))->toBe(route('workout.summary', $session))
        ->and($session->status)->toBe(SessionStatus::Completed)
        ->and($session->sets_completed)->toBe(4)
        ->and($session->volume_kg)->toBe(60 * 10 * 3 + 22.5 * 9)
        ->and($session->active_seconds)->toBe(2700)
        ->and($session->exercises->where('status', 'done')->count())->toBe(2)
        ->and($session->exercises->where('status', 'skipped')->count())->toBe(5)
        ->and($session->xp_earned)->toBe(4 * 5 + 50);

    $user->refresh();
    expect($user->xp_total)->toBe(70)->and($user->workout_streak)->toBe(1)->and($user->last_workout_on->toDateString())->toBe('2026-10-12');

    // Hit 10 reps on every set at 60 kg: next time add 2.5 kg and restart at 6 reps.
    $target = ProgressionTarget::where('user_id', $user->id)->where('exercise_id', $bench->exercise_id)->firstOrFail();
    expect($target->target_weight_kg)->toBe(62.5)->and($target->target_reps)->toBe(6)->and($target->based_on_session_id)->toBe($session->id);

    // Next session pre-fills that weight.
    Carbon::setTestNow('2026-10-19 09:00:00');
    $next = startToday($user);
    expect($next->exercises->first()->target_weight_kg)->toBe(62.5);
});

test('finishing twice is idempotent', function () {
    $user = trainer();
    $session = startToday($user);
    logSet($this, $user, $session, $session->exercises->first(), 1, 60, 8);

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();

    expect($user->refresh()->xp_total)->toBe(55)
        ->and(XpEvent::where('type', 'workout_complete')->count())->toBe(1);
});

test('reported active time cannot exceed the wall clock', function () {
    $user = trainer();
    $session = startToday($user);
    logSet($this, $user, $session, $session->exercises->first(), 1, 60, 8);

    Carbon::setTestNow('2026-10-12 09:30:00'); // 30 minutes after start
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish", ['active_seconds' => 20000])->assertOk();

    expect($session->refresh()->active_seconds)->toBeLessThanOrEqual(30 * 60 + 60);
});

test('a closed session rejects more sets', function () {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->first();
    logSet($this, $user, $session, $row, 1, 60, 8);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();

    logSet($this, $user, $session, $row, 2, 60, 8)->assertStatus(409);
});

test('abandoning closes the session without crediting the day', function () {
    $user = trainer();
    $session = startToday($user);
    logSet($this, $user, $session, $session->exercises->first(), 1, 60, 8);

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/abandon")->assertOk()->assertJson(['redirect' => route('plan.index')]);

    expect($session->refresh()->status)->toBe(SessionStatus::Abandoned)
        ->and($user->refresh()->workout_streak)->toBe(0)
        ->and(XpEvent::where('type', 'workout_complete')->count())->toBe(0);
    $this->actingAs($user)->get("/workout/{$session->id}")->assertRedirect(route('plan.index'));
});

test('abandoning and restarting the same day cannot farm set xp', function () {
    $user = trainer();
    $session = startToday($user);
    logSet($this, $user, $session, $session->exercises->first(), 1, 60, 8);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/abandon");
    expect($user->refresh()->xp_total)->toBe(5);

    $again = startToday($user);
    logSet($this, $user, $again, $again->exercises->first(), 1, 60, 8);

    expect($again->id)->toBe($session->id)->and($user->refresh()->xp_total)->toBe(5);
});

test('a personal record is flagged, pays bonus xp once per exercise and is shown on the summary', function () {
    $user = trainer();
    // Last week: bench 60 x 8.
    Carbon::setTestNow('2026-10-05 09:00:00');
    $old = startToday($user);
    logSet($this, $user, $old, $old->exercises->first(), 1, 60, 8);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$old->id}/finish")->assertOk();

    Carbon::setTestNow('2026-10-12 09:00:00');
    $session = startToday($user);
    $bench = $session->exercises->first();

    $first = logSet($this, $user, $session, $bench, 1, 65, 8)->assertCreated();
    $second = logSet($this, $user, $session, $bench, 2, 67.5, 8)->assertCreated();

    expect($first->json('isPr'))->toBeTrue()->and($first->json('xp'))->toBe(5 + 25)
        ->and($second->json('isPr'))->toBeTrue()->and($second->json('xp'))->toBe(5)   // PR bonus only once per exercise
        ->and(PersonalRecord::where(['user_id' => $user->id, 'type' => 'max_weight'])->value('value'))->toBe(67.5);

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();
    $this->actingAs($user)->get("/workout/{$session->id}/summary")->assertOk()->assertSee('Personal records')->assertSee('Flat Barbell Bench Press')->assertSee('67.5 kg');
});

test('the summary shows totals, xp breakdown and the next target', function () {
    $user = trainer();
    $session = startToday($user);
    foreach ([1, 2, 3] as $n) {
        logSet($this, $user, $session, $session->exercises->first(), $n, 60, 10);
    }
    Carbon::setTestNow('2026-10-12 09:40:00');
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish", ['active_seconds' => 2300])->assertOk();

    $this->actingAs($user)->get("/workout/{$session->id}/summary")
        ->assertOk()
        ->assertSee('Workout complete')
        ->assertSee('38:20')
        ->assertSee('1,800')
        ->assertSee('Sets completed')
        ->assertSee('Workout bonus')
        ->assertSee('Next time:')
        ->assertSee('62.5 kg');
});

test('an unfinished session redirects from the summary back to the game', function () {
    $user = trainer();
    $session = startToday($user);

    $this->actingAs($user)->get("/workout/{$session->id}/summary")->assertRedirect(route('workout.play', $session));
});

test('exercises can be swapped inside a session before any sets are logged', function () {
    $user = trainer();
    $session = startToday($user);
    $row = $session->exercises->get(1); // incline dumbbell press
    $replacement = Exercise::where('slug', 'dumbbell-bench-press')->firstOrFail();

    $alternatives = $this->actingAs($user)->getJson("/api/workout-sessions/{$session->id}/exercises/{$row->id}/alternatives")->assertOk()->json('alternatives');
    expect(collect($alternatives)->pluck('name'))->toContain('Dumbbell Bench Press');

    $res = $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/exercises/{$row->id}/swap", ['exercise_id' => $replacement->id])->assertOk();

    expect($res->json('exercise.name'))->toBe('Dumbbell Bench Press')->and($res->json('exercise.swappedFrom'))->toBe('Incline Dumbbell Press');
    $row->refresh();
    expect($row->exercise_id)->toBe($replacement->id)->and($row->original_exercise_id)->not->toBeNull();

    // After logging a set it is locked, and invalid targets are refused.
    logSet($this, $user, $session, $row, 1, 20, 10);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/exercises/{$row->id}/swap", ['exercise_id' => Exercise::where('slug', 'machine-chest-press')->value('id')])->assertStatus(422);
    $other = $session->exercises->get(2);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/exercises/{$other->id}/swap", ['exercise_id' => Exercise::where('slug', 'barbell-squat')->value('id')])->assertStatus(422);
});

test('a skipped exercise stays skipped and does not block finishing', function () {
    $user = trainer();
    $session = startToday($user);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/exercises/{$session->exercises->get(2)->id}/skip")->assertOk();
    logSet($this, $user, $session, $session->exercises->first(), 1, 60, 8);

    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();

    expect($session->exercises()->where('position', 3)->first()->status)->toBe('skipped');
});

test('one-off swaps are consumed by a finished workout', function () {
    $user = trainer();
    $row = PlanDayExercise::whereHas('exercise', fn ($q) => $q->where('slug', 'incline-dumbbell-press'))->firstOrFail();
    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => Exercise::where('slug', 'dumbbell-bench-press')->value('id'), 'permanent' => false]);

    $session = startToday($user);
    expect($session->exercises->get(1)->exercise->slug)->toBe('dumbbell-bench-press');
    logSet($this, $user, $session, $session->exercises->first(), 1, 60, 8);
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();

    expect(UserExerciseSwap::count())->toBe(0);
});

test('a skipped day can be trained later and the old snapshot is replaced', function () {
    $user = trainer();
    $this->actingAs($user)->post('/plan/1/skip', ['date' => '2026-10-12']);

    $session = startToday($user);

    expect(WorkoutSession::count())->toBe(1)->and($session->status)->toBe(SessionStatus::InProgress)->and($session->exercises)->toHaveCount(7);
});

test('the plan day page shows last result and next target once an exercise has history', function () {
    $user = trainer();

    $this->actingAs($user)->get('/plan/1')->assertOk()->assertDontSee('Next target');

    $session = startToday($user);
    $bench = $session->exercises->first();
    foreach ([1, 2, 3] as $n) {
        logSet($this, $user, $session, $bench, $n, 60, 10);
    }
    $this->actingAs($user)->postJson("/api/workout-sessions/{$session->id}/finish")->assertOk();

    $this->actingAs($user)->get('/plan/1')->assertOk()
        ->assertSee('Last time')
        ->assertSee('60 kg × 10, 10, 10')
        ->assertSee('Next target')
        ->assertSee('62.5 kg × 6')
        ->assertSee('add 2.5 kg');
});
