<?php

use App\Models\NotificationSetting;
use App\Models\User;

beforeEach(fn () => seedPlan());

test('the profile page renders for an onboarded user', function () {
    $this->actingAs(User::factory()->onboarded()->create())
        ->get('/profile')->assertOk()->assertSee('Profile and settings')->assertSee('Body, goal and training');
});

test('account details can be updated', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->patch('/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->name)->toBe('New Name')->and($user->email)->toBe('new@example.com');
});

test('the email must stay unique', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->patch('/profile', ['name' => 'X', 'email' => 'taken@example.com'])->assertSessionHasErrors('email');
});

test('the body profile can be edited and logs a new weight data point', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->patch('/profile/body', bodyProfilePayload(['weight_kg' => 80, 'goal' => 'bulking', 'training_days' => [1, 3, 5]]))
        ->assertSessionHasNoErrors();

    $profile = $user->refresh()->profile;
    expect($profile->weight_kg)->toBe(80.0)
        ->and($profile->goal->value)->toBe('bulking')
        ->and($profile->days_per_week)->toBe(3)
        ->and($profile->trainingDays())->toBe([1, 3, 5])
        ->and($user->measurements()->latest('measured_on')->value('weight_kg'))->toBe(80.0);
});

test('settings update the profile and notification preferences', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->patch('/profile/settings', [
        'sound_enabled' => '0', 'voice_coach' => '1', 'water_reminders' => '1',
        'creatine_dose_g' => 5, 'sleep_goal_hours' => 8, 'workout_reminder_at' => '18:30',
    ])->assertSessionHasNoErrors();

    $profile = $user->refresh()->profile;
    $notifications = NotificationSetting::where('user_id', $user->id)->firstOrFail();
    expect($profile->sound_enabled)->toBeFalse()
        ->and($profile->voice_coach)->toBeTrue()
        ->and($notifications->water_reminders)->toBeTrue()
        ->and(substr($notifications->workout_reminder_at, 0, 5))->toBe('18:30');
});

test('settings reject out-of-range values', function () {
    $this->actingAs(User::factory()->onboarded()->create())
        ->patch('/profile/settings', ['water_goal_ml' => 99999, 'workout_reminder_at' => 'soon'])
        ->assertSessionHasErrors(['water_goal_ml', 'workout_reminder_at']);
});

test('an account can be deleted with the correct password', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->delete('/profile', ['password' => 'wrong'])->assertSessionHasErrorsIn('userDeletion', 'password');
    expect(User::find($user->id))->not->toBeNull();

    $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect(User::find($user->id))->toBeNull();
    $this->assertGuest();
});

test('a guest deletes the account by typing DELETE', function () {
    $guest = User::factory()->guest()->onboarded()->create();

    $this->actingAs($guest)->delete('/profile', ['confirm' => 'nope'])->assertSessionHasErrorsIn('userDeletion', 'confirm');
    $this->actingAs($guest)->delete('/profile', ['confirm' => 'DELETE'])->assertRedirect('/');
    expect(User::find($guest->id))->toBeNull();
});
