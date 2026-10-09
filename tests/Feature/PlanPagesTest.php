<?php

use App\Enums\SessionStatus;
use App\Models\PlanDay;
use App\Models\SessionExercise;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedPlan();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday, week 2 of the plan
});

afterEach(fn () => Carbon::setTestNow());

function planUser(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create();
}

test('the plan page lists all seven days of the week', function () {
    $this->actingAs(planUser())->get('/plan')
        ->assertOk()
        ->assertSee('Week 2')
        ->assertSee('12 Oct to 18 Oct')
        ->assertSeeInOrder(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], escape: false)
        ->assertSee('Progressive overload')
        ->assertSee('Compound lifts 2-3 min')
        ->assertSee('Isolation 60-90 s')
        ->assertSee('Muscle is built during recovery, not in the gym.');
});

test('week navigation shows other weeks', function () {
    $this->actingAs(planUser())->get('/plan?week=2026-10-05')->assertOk()->assertSee('Week 1')->assertSee('5 Oct to 11 Oct');
    $this->actingAs(planUser())->get('/plan?week=garbage')->assertOk()->assertSee('Week 2');
});

test('a day page shows exercises with sets, reps, focus and rest', function () {
    $this->actingAs(planUser())->get('/plan/1')
        ->assertOk()
        ->assertSee('Flat Barbell Bench Press')
        ->assertSee('Overall chest mass')
        ->assertSee('6-10')
        ->assertSee('Rest 2-3 min')
        ->assertSee('Rest 60-90 s')
        ->assertSee('7 exercises')
        ->assertSee('21 sets');
});

test('friday prints the wide-grip label for the shared lat pulldown', function () {
    $this->actingAs(planUser())->get('/plan/5')->assertOk()->assertSee('Lat Pulldown (Wide Grip)')->assertSee('Lat spread');
    $this->actingAs(planUser())->get('/plan/2')->assertOk()->assertSee('Lat width')->assertDontSee('Wide Grip');
});

test('rest days show the recovery guidance', function () {
    $this->actingAs(planUser())->get('/plan/6')
        ->assertOk()
        ->assertSee('Recovery day')
        ->assertSee('Muscle is built during recovery, not in the gym.')
        ->assertSee('5 g of creatine');
});

test('unknown weekdays are a 404', function () {
    $this->actingAs(planUser())->get('/plan/0')->assertNotFound();
    $this->actingAs(planUser())->get('/plan/8')->assertNotFound();
});

test('marking a past day complete awards xp, a streak and snapshots the exercises', function () {
    $user = planUser();

    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12'])
        ->assertRedirect(route('plan.day', ['day' => 1, 'date' => '2026-10-12']))
        ->assertSessionHas('status');

    $session = WorkoutSession::where('user_id', $user->id)->firstOrFail();
    expect($session->status)->toBe(SessionStatus::Completed)
        ->and($session->source->value)->toBe('manual')
        ->and($session->week_number)->toBe(2)
        ->and($session->scheduled_for->toDateString())->toBe('2026-10-12')
        ->and(SessionExercise::where('workout_session_id', $session->id)->count())->toBe(7);

    $user->refresh();
    expect($user->xp_total)->toBe(50)->and($user->workout_streak)->toBe(1)->and($user->last_workout_on->toDateString())->toBe('2026-10-12');
});

test('completing the same day twice is idempotent', function () {
    $user = planUser();

    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);
    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);

    expect(WorkoutSession::count())->toBe(1)->and($user->refresh()->xp_total)->toBe(50);
});

test('a day can only be logged on the matching weekday, not in the future, not too long ago', function (string $day, string $date) {
    $this->actingAs(planUser())->post("/plan/{$day}/complete", ['date' => $date])->assertSessionHasErrors('date');
    expect(WorkoutSession::count())->toBe(0);
})->with([
    'wrong weekday' => ['1', '2026-10-13'],
    'future day' => ['5', '2026-10-16'],
    'too far back' => ['1', '2026-09-14'],
    'rest day' => ['6', '2026-10-10'],
]);

test('a day can be skipped and the skip undone', function () {
    $user = planUser();

    $this->actingAs($user)->post('/plan/2/skip', ['date' => '2026-10-13'])->assertSessionHasNoErrors();
    expect(WorkoutSession::first()->status)->toBe(SessionStatus::Skipped);
    $this->actingAs($user)->get('/plan/2?date=2026-10-13')->assertSee('Skipped')->assertSee('Undo skip');

    $this->actingAs($user)->delete('/plan/2/skip', ['date' => '2026-10-13']);
    expect(WorkoutSession::count())->toBe(0);
});

test('a skipped day can still be completed', function () {
    $user = planUser();
    $this->actingAs($user)->post('/plan/2/skip', ['date' => '2026-10-13']);
    $this->actingAs($user)->post('/plan/2/complete', ['date' => '2026-10-13']);

    expect(WorkoutSession::count())->toBe(1)->and(WorkoutSession::first()->status)->toBe(SessionStatus::Completed);
});

test('a completed day cannot be skipped and its skip cannot be undone', function () {
    $user = planUser();
    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);

    $this->actingAs($user)->post('/plan/1/skip', ['date' => '2026-10-12'])->assertSessionHasErrors('date');
    $this->actingAs($user)->delete('/plan/1/skip', ['date' => '2026-10-12']);

    expect(WorkoutSession::first()->status)->toBe(SessionStatus::Completed);
});

test('the plan page reflects completed and skipped days', function () {
    $user = planUser();
    $this->actingAs($user)->post('/plan/1/complete', ['date' => '2026-10-12']);
    $this->actingAs($user)->post('/plan/2/skip', ['date' => '2026-10-13']);

    $this->actingAs($user)->get('/plan')->assertOk()->assertSee('Completed')->assertSee('Skipped')->assertSee('Today');
});

test('one user cannot see or change another users sessions', function () {
    $owner = planUser();
    $other = planUser();
    $this->actingAs($owner)->post('/plan/1/complete', ['date' => '2026-10-12']);

    $this->actingAs($other)->get('/plan/1?date=2026-10-12')->assertDontSee('Completed');
    expect(WorkoutSession::where('user_id', $other->id)->count())->toBe(0);
});

test('plan pages require login and onboarding', function () {
    $this->get('/plan')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/plan')->assertRedirect(route('onboarding.show'));
});

test('every plan day is reachable', function () {
    $user = planUser();
    foreach (PlanDay::pluck('day_of_week') as $dow) {
        $this->actingAs($user)->get("/plan/{$dow}")->assertOk();
    }
});
