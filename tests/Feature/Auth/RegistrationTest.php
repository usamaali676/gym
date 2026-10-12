<?php

use App\Models\User;

test('registration screen can be rendered', function () {
    $this->get('/register')->assertOk()->assertSee('Create your account');
});

test('new users can register and are sent to onboarding', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.show'));

    $user = User::where('email', 'test@example.com')->firstOrFail();
    expect($user->is_guest)->toBeFalse()
        ->and($user->isOnboarded())->toBeFalse()
        ->and($user->role->value)->toBe('user');
});

test('weak passwords are rejected', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('users cannot grant themselves admin through registration', function () {
    $this->post('/register', [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
        'xp_total' => 99999,
    ]);

    $user = User::where('email', 'sneaky@example.com')->firstOrFail();
    expect($user->isAdmin())->toBeFalse()->and($user->xp_total)->toBe(0);
});
