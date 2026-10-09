<?php

use App\Models\Measurement;
use App\Models\User;

beforeEach(fn () => seedPlan());

test('guests of the site are sent to login', function () {
    $this->get('/onboarding')->assertRedirect('/login');
    $this->get('/dashboard')->assertRedirect('/login');
});

test('users who have not finished onboarding are redirected to the wizard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.show'));
    $this->actingAs($user)->get('/profile')->assertRedirect(route('onboarding.show'));
});

test('the wizard renders with its four steps', function () {
    $this->actingAs(User::factory()->create())
        ->get('/onboarding')
        ->assertOk()
        ->assertSee('Let us get to know you')
        ->assertSee('Your goal')
        ->assertSee('Your setup');
});

test('completing the wizard saves the profile and unlocks the app', function () {
    $user = User::factory()->create(['name' => 'Temp']);

    $this->actingAs($user)->post('/onboarding', bodyProfilePayload(['timezone' => 'Asia/Karachi']))
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    $profile = $user->profile;

    expect($user->isOnboarded())->toBeTrue()
        ->and($user->name)->toBe('Mujtaba Ahssan')
        ->and($profile->age)->toBe(27)
        ->and($profile->height_cm)->toBe(178.0)
        ->and($profile->weight_kg)->toBe(78.0)
        ->and($profile->goal->value)->toBe('recomposition')
        ->and($profile->days_per_week)->toBe(5)
        ->and($profile->timezone)->toBe('Asia/Karachi')
        ->and($profile->plan_id)->not->toBeNull()
        ->and($profile->equipment)->toBe(['barbell', 'dumbbell', 'cable']);

    // The starting weight becomes the first progress data point.
    expect(Measurement::where('user_id', $user->id)->value('weight_kg'))->toBe(78.0);
    expect($user->notificationSetting)->not->toBeNull();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('onboarding validates its input', function (array $override, string $field) {
    $this->actingAs(User::factory()->create())
        ->post('/onboarding', bodyProfilePayload($override))
        ->assertSessionHasErrors($field);
})->with([
    'age too low' => [['age' => 10], 'age'],
    'unknown sex' => [['sex' => 'robot'], 'sex'],
    'height too small' => [['height_cm' => 20], 'height_cm'],
    'weight too large' => [['weight_kg' => 900], 'weight_kg'],
    'bad goal' => [['goal' => 'get-huge'], 'goal'],
    'no equipment' => [['equipment' => []], 'equipment'],
    'unknown equipment' => [['equipment' => ['jetpack']], 'equipment.0'],
    'bad weekday' => [['training_days' => [9]], 'training_days.0'],
    'bad timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
]);

test('onboarded users are sent to the profile page instead of the wizard', function () {
    $this->actingAs(User::factory()->onboarded()->create())->get('/onboarding')->assertRedirect(route('profile.edit'));
});
