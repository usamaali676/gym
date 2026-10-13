<?php

use App\Enums\SessionStatus;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPlan();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday
});

afterEach(fn () => Carbon::setTestNow());

function sessionOn(User $user, string $date, SessionStatus $status): WorkoutSession
{
    return WorkoutSession::create([
        'user_id' => $user->id, 'scheduled_for' => $date, 'status' => $status, 'client_uuid' => (string) Str::uuid(),
    ]);
}

test('week overview classifies each day', function () {
    $user = User::factory()->onboarded()->create();
    sessionOn($user, '2026-10-12', SessionStatus::Completed); // Monday done, Tuesday missed

    $statuses = app(PlanService::class)->weekOverview($user)->pluck('status')->all();

    expect($statuses)->toBe(['completed', 'missed', 'today', 'upcoming', 'upcoming', 'rest', 'rest']);
});

test('skipped and in-progress sessions are reported', function () {
    $user = User::factory()->onboarded()->create();
    sessionOn($user, '2026-10-12', SessionStatus::Skipped);
    sessionOn($user, '2026-10-14', SessionStatus::InProgress);

    $statuses = app(PlanService::class)->weekOverview($user)->pluck('status')->all();

    expect($statuses[0])->toBe('skipped')->and($statuses[2])->toBe('in_progress');
});

test('week progress counts completed training days against the target', function () {
    $user = User::factory()->onboarded()->create();
    sessionOn($user, '2026-10-12', SessionStatus::Completed);
    sessionOn($user, '2026-10-13', SessionStatus::Completed);

    expect(app(PlanService::class)->weekProgress($user))->toBe(['done' => 2, 'target' => 5]);
});

test('days the user switched off count as rest', function () {
    $user = User::factory()->onboarded(['training_days' => [1, 3, 5]])->create();
    $service = app(PlanService::class);

    expect($service->weeklyTarget($user))->toBe(3)
        ->and($service->isTrainingDay($user, Carbon::parse('2026-10-13')))->toBeFalse()   // Tuesday
        ->and($service->isTrainingDay($user, Carbon::parse('2026-10-14')))->toBeTrue()    // Wednesday
        ->and($service->isTrainingDay($user, Carbon::parse('2026-10-17')))->toBeFalse();  // Saturday (plan rest)
});

test('week number counts from the plan start', function () {
    $user = User::factory()->onboarded(['plan_started_on' => '2026-10-05'])->create();
    $service = app(PlanService::class);

    expect($service->weekNumber($user, Carbon::parse('2026-10-05')))->toBe(1)
        ->and($service->weekNumber($user, Carbon::parse('2026-10-11')))->toBe(1)
        ->and($service->weekNumber($user, Carbon::parse('2026-10-12')))->toBe(2)
        ->and($service->weekNumber($user, Carbon::parse('2026-10-01')))->toBe(1);
});

test('estimated duration uses sets, work time and the rest defaults', function () {
    $user = User::factory()->onboarded()->create();
    $service = app(PlanService::class);

    $monday = $service->dayFor($user, Carbon::parse('2026-10-12'));
    $wednesday = $service->dayFor($user, Carbon::parse('2026-10-14'));
    $saturday = $service->dayFor($user, Carbon::parse('2026-10-17'));

    expect($service->estimatedMinutes($monday))->toBe(28)
        ->and($service->estimatedMinutes($wednesday))->toBe(28)
        ->and($service->estimatedMinutes($saturday))->toBe(0);
});
