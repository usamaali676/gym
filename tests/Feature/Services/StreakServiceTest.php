<?php

use App\Models\User;
use App\Services\StreakService;
use Illuminate\Support\Carbon;

beforeEach(fn () => seedPlan());
afterEach(fn () => Carbon::setTestNow());

function streakUser(): User
{
    return User::factory()->onboarded()->create();
}

test('consecutive training days build the streak', function () {
    $user = streakUser();
    $streaks = app(StreakService::class);

    expect($streaks->recordWorkout($user, Carbon::parse('2026-10-12')))->toBe(1)   // Mon
        ->and($streaks->recordWorkout($user, Carbon::parse('2026-10-13')))->toBe(2)  // Tue
        ->and($streaks->recordWorkout($user, Carbon::parse('2026-10-13')))->toBe(2); // same day again
});

test('a missed training day resets the streak', function () {
    $user = streakUser();
    $streaks = app(StreakService::class);

    $streaks->recordWorkout($user, Carbon::parse('2026-10-12')); // Mon
    $streaks->recordWorkout($user, Carbon::parse('2026-10-13')); // Tue
    // Wednesday missed
    expect($streaks->recordWorkout($user, Carbon::parse('2026-10-15')))->toBe(1); // Thu

    expect($user->refresh()->longest_workout_streak)->toBe(2);
});

test('rest days over the weekend never break the streak', function () {
    $user = streakUser();
    $streaks = app(StreakService::class);

    $streaks->recordWorkout($user, Carbon::parse('2026-10-16')); // Fri
    expect($streaks->recordWorkout($user, Carbon::parse('2026-10-19')))->toBe(2); // next Mon
});

test('the displayed streak drops to zero once a training day is missed', function () {
    $user = streakUser();
    $streaks = app(StreakService::class);
    $streaks->recordWorkout($user, Carbon::parse('2026-10-15')); // Thu
    $streaks->recordWorkout($user, Carbon::parse('2026-10-16')); // Fri

    Carbon::setTestNow('2026-10-18 10:00:00'); // Sunday: Sat + Sun are rest days
    expect($streaks->current($user->refresh()))->toBe(2);

    Carbon::setTestNow('2026-10-19 10:00:00'); // Monday morning: today is still open
    expect($streaks->current($user->refresh()))->toBe(2);

    Carbon::setTestNow('2026-10-20 10:00:00'); // Tuesday: Monday was missed
    expect($streaks->current($user->refresh()))->toBe(0);
});

test('a user who never trained has no streak', function () {
    expect(app(StreakService::class)->current(streakUser()))->toBe(0);
});

test('back-filling an older day does not change the streak', function () {
    $user = streakUser();
    $streaks = app(StreakService::class);
    $streaks->recordWorkout($user, Carbon::parse('2026-10-13'));

    expect($streaks->recordWorkout($user, Carbon::parse('2026-10-12')))->toBe(1);
});
