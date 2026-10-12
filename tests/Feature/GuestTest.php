<?php

use App\Models\User;

test('a visitor can start a guest session', function () {
    $this->post('/guest')->assertRedirect(route('onboarding.show'));

    $this->assertAuthenticated();
    $guest = User::firstOrFail();
    expect($guest->is_guest)->toBeTrue()->and($guest->email)->toBeNull()->and($guest->password)->toBeNull();
});

test('guest mode can be switched off', function () {
    config(['ironpulse.guest_mode' => false]);

    $this->post('/guest')->assertNotFound();
    $this->assertGuest();
});

test('a logged-in user cannot start another guest session', function () {
    $this->actingAs(User::factory()->create())->post('/guest')->assertRedirect();
    expect(User::count())->toBe(1);
});

test('a guest can claim the account in place without losing data', function () {
    seedPlan();
    $guest = User::factory()->guest()->onboarded()->create();
    $id = $guest->id;

    $this->actingAs($guest)->post('/account/claim', [
        'email' => 'keeper@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('dashboard'));

    $guest->refresh();
    expect($guest->id)->toBe($id)
        ->and($guest->is_guest)->toBeFalse()
        ->and($guest->email)->toBe('keeper@example.com')
        ->and($guest->profile)->not->toBeNull();
});

test('registered users cannot use the claim endpoint', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->post('/account/claim', [
        'email' => 'other@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertForbidden();
});
