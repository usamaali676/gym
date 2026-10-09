<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** A finished-onboarding user with a full profile on the default plan (if seeded). */
    public function onboarded(array $profile = []): static
    {
        return $this->state(fn () => ['onboarded_at' => now()])
            ->has(ProfileFactory::new()->state($profile), 'profile');
    }

    public function guest(): static
    {
        return $this->state(fn () => ['is_guest' => true, 'email' => null, 'password' => null, 'name' => 'Guest']);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }
}
