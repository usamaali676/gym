<?php

use App\Models\Exercise;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Models\SessionExercise;
use App\Models\User;
use App\Models\UserExerciseSwap;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    Carbon::setTestNow('2026-10-14 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function planRow(string $slug, int $dow): PlanDayExercise
{
    return PlanDayExercise::whereHas('exercise', fn ($q) => $q->where('slug', $slug))
        ->whereHas('day', fn ($q) => $q->where('day_of_week', $dow))->firstOrFail();
}

test('alternatives share the muscle group and respect the equipment', function () {
    $user = User::factory()->onboarded(['equipment' => ['dumbbell', 'bench']])->create();
    $row = planRow('incline-dumbbell-press', 1);

    $names = collect($this->actingAs($user)->getJson("/api/plan-items/{$row->id}/alternatives")->assertOk()->json('alternatives'))->pluck('name');

    expect($names)->toContain('Dumbbell Bench Press', 'Incline Push-Up', 'Dumbbell Fly')
        ->and($names)->not->toContain('Machine Chest Press', 'Cable Lower Chest Fly', 'Flat Barbell Bench Press', 'Incline Dumbbell Press', 'Barbell Squat');
});

test('a full gym sees plan exercises of the same group as alternatives too', function () {
    $user = User::factory()->onboarded()->create();
    $row = planRow('incline-dumbbell-press', 1);

    $names = collect($this->actingAs($user)->getJson("/api/plan-items/{$row->id}/alternatives")->json('alternatives'))->pluck('name');

    expect($names)->toContain('Flat Barbell Bench Press', 'Machine Chest Press', 'Butterfly Machine');
});

test('swapping stores the replacement and the day page shows it', function () {
    $user = User::factory()->onboarded()->create();
    $row = planRow('incline-dumbbell-press', 1);
    $replacement = Exercise::where('slug', 'machine-chest-press')->firstOrFail();

    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => $replacement->id, 'permanent' => true])
        ->assertCreated()->assertJson(['ok' => true, 'permanent' => true]);

    expect(UserExerciseSwap::where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($user)->get('/plan/1')->assertSee('Machine Chest Press')->assertSee('Swapped from Incline Dumbbell Press');
    // The plan itself is untouched, and other users still see the PDF exercise.
    $this->actingAs(User::factory()->onboarded()->create())->get('/plan/1')->assertSee('Incline Dumbbell Press')->assertDontSee('Machine Chest Press');
});

test('a swap can be reverted', function () {
    $user = User::factory()->onboarded()->create();
    $row = planRow('incline-dumbbell-press', 1);
    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => Exercise::where('slug', 'machine-chest-press')->value('id')]);

    $this->actingAs($user)->deleteJson("/api/plan-items/{$row->id}/swap")->assertOk();

    expect(UserExerciseSwap::count())->toBe(0);
});

test('only offered alternatives can be swapped in', function (string $slug) {
    $user = User::factory()->onboarded(['equipment' => ['dumbbell', 'bench']])->create();
    $row = planRow('incline-dumbbell-press', 1);

    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => Exercise::where('slug', $slug)->value('id')])
        ->assertStatus(422)->assertJsonValidationErrors('exercise_id');
    expect(UserExerciseSwap::count())->toBe(0);
})->with([
    'different muscle group' => ['barbell-squat'],
    'equipment not owned' => ['machine-chest-press'],
    'the same exercise' => ['incline-dumbbell-press'],
]);

test('an unknown exercise id is rejected', function () {
    $user = User::factory()->onboarded()->create();
    $row = planRow('incline-dumbbell-press', 1);

    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => 99999])->assertStatus(422);
});

test('users cannot swap rows of a plan they do not follow', function () {
    $user = User::factory()->onboarded()->create();
    $otherPlan = Plan::create(['slug' => 'other', 'name' => 'Other plan']);
    $day = PlanDay::create(['plan_id' => $otherPlan->id, 'day_of_week' => 1, 'slug' => 'x', 'name' => 'Monday', 'session_type' => 'push', 'label' => 'PUSH', 'focus' => 'X']);
    $foreign = PlanDayExercise::create(['plan_day_id' => $day->id, 'exercise_id' => Exercise::where('slug', 'barbell-squat')->value('id'), 'position' => 1, 'sets' => 3, 'reps_min' => 5, 'reps_max' => 8, 'focus' => 'x']);

    $exerciseId = Exercise::where('slug', 'barbell-squat')->value('id'); // not "1": ids are not reset between tests on MySQL

    $this->actingAs($user)->getJson("/api/plan-items/{$foreign->id}/alternatives")->assertForbidden();
    $this->actingAs($user)->postJson("/api/plan-items/{$foreign->id}/swap", ['exercise_id' => $exerciseId])->assertForbidden();
    $this->actingAs($user)->deleteJson("/api/plan-items/{$foreign->id}/swap")->assertForbidden();
});

test('the swap api requires login', function () {
    $row = planRow('incline-dumbbell-press', 1);
    $this->getJson("/api/plan-items/{$row->id}/alternatives")->assertUnauthorized();
});

test('a one-off swap is used for the next session and then disappears', function () {
    $user = User::factory()->onboarded()->create();
    $row = planRow('incline-dumbbell-press', 1);
    $replacement = Exercise::where('slug', 'dumbbell-bench-press')->firstOrFail();
    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => $replacement->id, 'permanent' => false]);

    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);

    $snapshot = SessionExercise::where('plan_day_exercise_id', $row->id)->firstOrFail();
    expect($snapshot->exercise_id)->toBe($replacement->id)
        ->and($snapshot->original_exercise_id)->toBe($row->exercise_id)
        ->and(UserExerciseSwap::count())->toBe(0);
});

test('a permanent swap survives a completed session', function () {
    $user = User::factory()->onboarded()->create();
    $row = planRow('incline-dumbbell-press', 1);
    $this->actingAs($user)->postJson("/api/plan-items/{$row->id}/swap", ['exercise_id' => Exercise::where('slug', 'dumbbell-bench-press')->value('id'), 'permanent' => true]);

    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);

    expect(UserExerciseSwap::count())->toBe(1);
});
