<?php

use App\Enums\SessionStatus;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPlan();
    // Monday 12 Oct 2026 (ISO weekday 1).
    Carbon::setTestNow('2026-10-12 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

test('monday shows the push chest workout from the seeded plan', function () {
    $user = User::factory()->onboarded()->create(['name' => 'Mujtaba Ahssan']);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Mujtaba')
        ->assertSee('PUSH')
        ->assertSee('Chest')
        ->assertSee('7 exercises')
        ->assertSee('21 sets')
        ->assertSee('Flat Barbell Bench Press')
        ->assertSee('3×6-10', escape: false)
        ->assertSee('about 58 min');
});

test('saturday is a rest day with the recovery message', function () {
    Carbon::setTestNow('2026-10-17 09:00:00');
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Rest day')
        ->assertSee('Muscle is built during recovery, not in the gym.');
});

test('the dashboard uses the profile timezone to decide what today is', function () {
    // 23:30 UTC on Sunday is already Monday in Karachi (UTC+5).
    Carbon::setTestNow('2026-10-11 23:30:00');
    $user = User::factory()->onboarded(['timezone' => 'Asia/Karachi'])->create();

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Chest')->assertSee('Monday');
});

test('a completed session shows as completed and fills the week ring', function () {
    $user = User::factory()->onboarded()->create();
    WorkoutSession::create([
        'user_id' => $user->id, 'plan_day_id' => $user->profile->plan->days()->where('day_of_week', 1)->value('id'),
        'scheduled_for' => '2026-10-12', 'status' => SessionStatus::Completed, 'client_uuid' => (string) Str::uuid(),
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Completed')
        ->assertSee('data-ring="0.2"', escape: false);
});

test('the dashboard stays within a small query budget', function () {
    $user = User::factory()->onboarded()->create();

    DB::enableQueryLog();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThan(30);
});
