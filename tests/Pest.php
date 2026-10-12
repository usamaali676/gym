<?php

use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\ExerciseLibrarySeeder;
use Database\Seeders\FoodSeeder;
use Database\Seeders\MealTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PostureRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
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

function seedLibrary(): void
{
    seedPlan();
    test()->seed(ExerciseLibrarySeeder::class);
}

function seedPosture(): void
{
    seedLibrary();
    test()->seed(PostureRuleSeeder::class);
}

function seedBadges(): void
{
    test()->seed(BadgeSeeder::class);
}

function seedFoods(): void
{
    test()->seed(FoodSeeder::class);
    test()->seed(MealTemplateSeeder::class);
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
| Mobile API helpers
*/

/** A valid `device` block for sign-in requests. */
function deviceBlock(array $overrides = []): array
{
    return array_merge([
        'installation_id' => (string) Str::uuid(),
        'platform' => 'android',
        'name' => 'Pixel 8',
        'app_version' => '1.0.0',
        'app_build' => '1',
        'os_version' => '15',
        'locale' => 'en-US',
        'timezone' => 'UTC',
    ], $overrides);
}

/** A real Sanctum token for the user (so expiry, revocation and the hashed lookup are all exercised). */
function apiToken(User $user, ?DateTimeInterface $expiresAt = null): string
{
    return $user->createToken('test', ['*'], $expiresAt ?? now()->addDays(60))->plainTextToken;
}

/**
 * Authorization header for the given token. The auth manager caches the resolved user per guard, so it is reset
 * here to let one test call the API with several different tokens.
 *
 * @return array<string, string>
 */
function bearer(string $token): array
{
    $auth = app('auth');
    $web = $auth->guard('web')->user(); // an admin signed in with actingAs() in the same test must stay signed in

    $auth->forgetGuards();
    if ($web) {
        $auth->guard('web')->setUser($web);
    }

    return ['Authorization' => 'Bearer '.$token];
}

/** Headers for an onboarded user with a fresh token. */
function apiAs(User $user): array
{
    return bearer(apiToken($user));
}

/**
 * An upload backed by a real temp file. Laravel's UploadedFile::fake() reports its type from the file *name*, which
 * would make a content-sniffing test meaningless; this one is judged by its bytes, like a real upload.
 */
function realUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** A few bytes libmagic recognises as an MP4 container. */
function fakeMp4(string $name = 'clip.mp4'): UploadedFile
{
    return realUpload($name, pack('N', 24).'ftypmp42'.pack('N', 0).'mp42isom'.str_repeat("\0", 200));
}

function realImage(string $name = 'pic.png', string $format = 'png'): UploadedFile
{
    $img = imagecreatetruecolor(40, 20);
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($img),
        'webp' => imagewebp($img),
        default => imagepng($img),
    };

    return realUpload($name, ob_get_clean());
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
