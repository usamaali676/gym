<?php

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
| Helpers
*/
function seedPlan(): void
{
    test()->seed(PlanSeeder::class);
}

/** A valid onboarding / body-profile payload. */
function bodyProfilePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Mujtaba Ahssan',
        'age' => 27,
        'sex' => 'male',
        'height_cm' => 178,
        'weight_kg' => 78,
        'activity_level' => 'moderate',
        'fitness_level' => 'intermediate',
        'goal' => 'recomposition',
        'diet_preference' => 'nonveg',
        'equipment' => ['barbell', 'dumbbell', 'cable'],
        'training_days' => [1, 2, 3, 4, 5],
        'unit_system' => 'metric',
        'timezone' => 'UTC',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
