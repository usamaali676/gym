<?php

use App\Models\Food;
use App\Models\FoodLog;
use App\Models\User;
use App\Services\DietPlanService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    seedFoods();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday
});
afterEach(fn () => Carbon::setTestNow());

function eater(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create();
}

function foodNamed(string $name): Food
{
    return Food::with('servings')->where('name', $name)->firstOrFail();
}

function logFood($test, User $user, string $name, array $over = [])
{
    $food = foodNamed($name);

    return $test->actingAs($user)->postJson('/api/food-logs', array_merge([
        'food_id' => $food->id, 'logged_on' => '2026-10-14', 'meal' => 'lunch', 'quantity' => 150, 'unit' => 'grams',
    ], $over));
}

// ---------------------------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------------------------

test('search puts names that start with the query first', function () {
    $user = eater(['diet_preference' => 'nonveg']);

    $names = collect($this->actingAs($user)->getJson('/api/foods/search?q=milk')->assertOk()->json('foods'))->pluck('name');

    expect($names->take(3)->every(fn ($n) => str_starts_with($n, 'Milk')))->toBeTrue()
        ->and($names)->toContain('Soy milk, unsweetened', 'Almond milk, unsweetened', 'Milk, whole');
});

test('search matches any word order and is case insensitive', function () {
    $user = eater();

    $names = collect($this->actingAs($user)->getJson('/api/foods/search?q=BASMATI')->json('foods'))->pluck('name');
    expect($names)->toContain('Basmati rice, cooked');

    $names = collect($this->actingAs($user)->getJson('/api/foods/search?q=rice+basmati')->json('foods'))->pluck('name');
    expect($names)->toContain('Basmati rice, cooked');
});

test('search results carry servings and per 100 g macros', function () {
    $user = eater();

    $food = $this->actingAs($user)->getJson('/api/foods/search?q=paneer')->json('foods.0');

    expect($food['name'])->toBe('Paneer')->and($food['protein_100g'])->toEqual(18.3)
        ->and($food['servings'])->not->toBeEmpty()->and($food['servings'][0]['is_default'])->toBeTrue();
});

test('search respects the diet preference unless the user asks for everything', function () {
    $vegan = eater(['diet_preference' => 'vegan']);

    $filtered = collect($this->actingAs($vegan)->getJson('/api/foods/search?q=chicken')->json('foods'));
    expect($filtered)->toBeEmpty();

    $all = $this->actingAs($vegan)->getJson('/api/foods/search?q=chicken&all=1')->json('foods');
    expect(collect($all)->pluck('name'))->toContain('Chicken breast, cooked');
});

test('wildcard characters in a search are plain text', function () {
    $user = eater();

    // "%" matches only names that really contain a percent sign (not everything), "_" matches nothing.
    $percent = collect($this->actingAs($user)->getJson('/api/foods/search?q=%25&all=1')->assertOk()->json('foods'))->pluck('name');
    expect($percent)->not->toBeEmpty()->and($percent->every(fn ($n) => str_contains($n, '%')))->toBeTrue();
    expect($this->actingAs($user)->getJson('/api/foods/search?q=_&all=1')->assertOk()->json('foods'))->toBeEmpty();
});

test('search needs a query and a login', function () {
    $this->getJson('/api/foods/search?q=rice')->assertUnauthorized();
    $this->actingAs(eater())->getJson('/api/foods/search')->assertStatus(422);
    $this->actingAs(eater())->getJson('/api/foods/search?q='.str_repeat('a', 81))->assertStatus(422);
});

test('recent foods are the ones the user logs most', function () {
    $user = eater();
    logFood($this, $user, 'Banana')->assertCreated();
    logFood($this, $user, 'Banana', ['meal' => 'snack'])->assertCreated();
    logFood($this, $user, 'Paneer')->assertCreated();

    $recent = collect($this->actingAs($user)->getJson('/api/foods/recent')->json('foods'))->pluck('name');

    expect($recent->first())->toBe('Banana')->and($recent)->toContain('Paneer');
});

// ---------------------------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------------------------

test('logging grams stores the macros for that amount and a name snapshot', function () {
    $user = eater();

    $res = logFood($this, $user, 'Chicken breast, cooked', ['quantity' => 200])->assertCreated();

    expect($res->json('log.kcal'))->toEqual(330.0)->and($res->json('log.protein_g'))->toEqual(62.0)
        ->and($res->json('log.fat_g'))->toEqual(7.2)->and($res->json('log.grams'))->toEqual(200.0)
        ->and($res->json('log.name'))->toBe('Chicken breast, cooked')
        ->and($res->json('day.totals.protein_g'))->toEqual(62.0);

    $row = FoodLog::firstOrFail();
    expect($row->user_id)->toBe($user->id)->and($row->unit)->toBe('grams')->and($row->logged_on->toDateString())->toBe('2026-10-14');
});

test('logging servings multiplies the serving weight', function () {
    $user = eater();
    $egg = foodNamed('Egg, whole, boiled');
    $serving = $egg->servings->firstWhere('is_default', true);

    $res = logFood($this, $user, 'Egg, whole, boiled', ['unit' => 'serving', 'quantity' => 3, 'serving_id' => $serving->id, 'meal' => 'breakfast'])->assertCreated();

    expect($res->json('log.grams'))->toEqual(150.0)->and($res->json('log.serving_label'))->toBe('1 large egg (50 g)')
        ->and($res->json('log.kcal'))->toEqual(232.5)->and($res->json('log.protein_g'))->toEqual(18.9);
});

test('a serving that belongs to another food is rejected', function () {
    $user = eater();
    $other = foodNamed('Paneer')->servings->first();

    logFood($this, $user, 'Banana', ['unit' => 'serving', 'quantity' => 1, 'serving_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('serving_id');
    logFood($this, $user, 'Banana', ['unit' => 'serving', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('serving_id');
});

test('invalid logs are rejected', function (array $override, string $field) {
    logFood($this, eater(), 'Banana', $override)->assertStatus(422)->assertJsonValidationErrors($field);
    expect(FoodLog::count())->toEqual(0);
})->with([
    'too many grams' => [['quantity' => 5001], 'quantity'],
    'zero grams' => [['quantity' => 0], 'quantity'],
    'unknown meal' => [['meal' => 'brunch'], 'meal'],
    'unknown unit' => [['unit' => 'cups'], 'unit'],
    'bad date' => [['logged_on' => 'tomorrow'], 'logged_on'],
    'too far in the future' => [['logged_on' => '2026-10-20'], 'logged_on'],
    'more than a year ago' => [['logged_on' => '2025-01-01'], 'logged_on'],
    'unknown food' => [['food_id' => 999999], 'food_id'],
]);

test('the day state after a log shows totals, targets and what is left', function () {
    $user = eater(['goal' => 'maintenance']);
    app(DietPlanService::class)->build($user->load('profile'));

    $res = logFood($this, $user, 'Chicken breast, cooked', ['quantity' => 200])->assertCreated();

    $day = $res->json('day');
    expect($day['targets']['protein_g'])->toBeGreaterThan(100)
        ->and($day['remaining']['protein_g'])->toEqual(round($day['targets']['protein_g'] - 62.0, 1))
        ->and($day['is_training_day'])->toBeTrue()   // Wednesday
        ->and($day['suggestions'])->not->toBeEmpty();
});

test('a log can be edited: amount, unit and meal', function () {
    $user = eater();
    $id = logFood($this, $user, 'Paneer', ['quantity' => 100])->json('log.id');

    $res = $this->actingAs($user)->patchJson("/api/food-logs/{$id}", ['quantity' => 50, 'unit' => 'grams', 'meal' => 'dinner'])->assertOk();

    expect($res->json('log.kcal'))->toEqual(148.0)->and($res->json('log.meal'))->toBe('dinner')->and($res->json('log.grams'))->toEqual(50.0);
});

test('editing keeps working after the food was deleted: the snapshot scales', function () {
    $user = eater();
    $custom = $this->actingAs($user)->postJson('/api/foods', ['name' => 'Protein pudding', 'diet_type' => 'vegetarian', 'kcal_100g' => 100, 'protein_100g' => 10, 'carbs_100g' => 10, 'fat_100g' => 2.5])->json('food');
    $id = $this->actingAs($user)->postJson('/api/food-logs', ['food_id' => $custom['id'], 'logged_on' => '2026-10-14', 'meal' => 'snack', 'quantity' => 200, 'unit' => 'grams'])->json('log.id');
    $this->actingAs($user)->deleteJson("/api/foods/{$custom['id']}")->assertNoContent();

    $res = $this->actingAs($user)->patchJson("/api/food-logs/{$id}", ['quantity' => 100, 'unit' => 'grams'])->assertOk();

    expect($res->json('log.kcal'))->toEqual(100.0)->and($res->json('log.protein_g'))->toEqual(10.0)->and($res->json('log.name'))->toBe('Protein pudding');
});

test('a log can be deleted and totals follow', function () {
    $user = eater();
    $id = logFood($this, $user, 'Banana')->json('log.id');

    $res = $this->actingAs($user)->deleteJson("/api/food-logs/{$id}")->assertOk();

    expect(FoodLog::count())->toEqual(0)->and($res->json('day.totals.calories'))->toEqual(0.0);
});

test("nobody can read, edit or delete someone else's log", function () {
    $a = eater();
    $b = eater();
    $id = logFood($this, $a, 'Banana')->json('log.id');

    $this->actingAs($b)->patchJson("/api/food-logs/{$id}", ['quantity' => 10, 'unit' => 'grams'])->assertForbidden();
    $this->actingAs($b)->deleteJson("/api/food-logs/{$id}")->assertForbidden();
    expect(FoodLog::count())->toEqual(1);
});

test("another user's custom food cannot be logged", function () {
    $a = eater();
    $b = eater();
    $custom = $this->actingAs($a)->postJson('/api/foods', ['name' => 'Secret sauce', 'diet_type' => 'vegan', 'kcal_100g' => 50, 'protein_100g' => 1, 'carbs_100g' => 10, 'fat_100g' => 1])->json('food');

    $this->actingAs($b)->postJson('/api/food-logs', ['food_id' => $custom['id'], 'logged_on' => '2026-10-14', 'meal' => 'lunch', 'quantity' => 50, 'unit' => 'grams'])->assertNotFound();
    expect(collect($this->actingAs($b)->getJson('/api/foods/search?q=secret&all=1')->json('foods')))->toBeEmpty();
    expect(collect($this->actingAs($a)->getJson('/api/foods/search?q=secret&all=1')->json('foods'))->pluck('name'))->toContain('Secret sauce');
});

test('a whole meal can be logged at once', function () {
    $user = eater();
    $items = [
        ['food_id' => foodNamed('Oats, rolled, dry')->id, 'grams' => 60],
        ['food_id' => foodNamed('Banana')->id, 'grams' => 120],
    ];

    $res = $this->actingAs($user)->postJson('/api/food-logs/batch', ['logged_on' => '2026-10-14', 'meal' => 'breakfast', 'items' => $items])->assertCreated();

    expect($res->json('logs'))->toHaveCount(2)->and(FoodLog::where('meal', 'breakfast')->count())->toEqual(2)
        ->and($res->json('day.totals.calories'))->toBe(round(379 * 0.6 + 89 * 1.2, 1));
});

test('a batch with a food the user cannot see logs nothing', function () {
    $a = eater();
    $b = eater();
    $secret = $this->actingAs($a)->postJson('/api/foods', ['name' => 'Private', 'diet_type' => 'vegan', 'kcal_100g' => 50, 'protein_100g' => 1, 'carbs_100g' => 10, 'fat_100g' => 1])->json('food.id');

    $this->actingAs($b)->postJson('/api/food-logs/batch', ['logged_on' => '2026-10-14', 'meal' => 'lunch', 'items' => [
        ['food_id' => foodNamed('Banana')->id, 'grams' => 100], ['food_id' => $secret, 'grams' => 100],
    ]])->assertStatus(422);

    expect(FoodLog::where('user_id', $b->id)->count())->toEqual(0);
});

test('copy yesterday duplicates entries into empty meals only', function () {
    $user = eater();
    logFood($this, $user, 'Oats, rolled, dry', ['logged_on' => '2026-10-13', 'meal' => 'breakfast', 'quantity' => 50])->assertCreated();
    logFood($this, $user, 'Chicken breast, cooked', ['logged_on' => '2026-10-13', 'meal' => 'lunch'])->assertCreated();
    logFood($this, $user, 'Banana', ['logged_on' => '2026-10-14', 'meal' => 'lunch', 'quantity' => 100])->assertCreated();

    $res = $this->actingAs($user)->postJson('/api/food-logs/copy', ['from' => '2026-10-13', 'to' => '2026-10-14'])->assertOk();

    expect($res->json('copied'))->toEqual(1)->and($res->json('skipped'))->toBe(['Lunch'])
        ->and(FoodLog::whereDate('logged_on', '2026-10-14')->count())->toEqual(2)
        ->and(FoodLog::whereDate('logged_on', '2026-10-14')->where('meal', 'breakfast')->firstOrFail()->name_snapshot)->toBe('Oats, rolled, dry');

    // pressing it again changes nothing
    $again = $this->actingAs($user)->postJson('/api/food-logs/copy', ['from' => '2026-10-13', 'to' => '2026-10-14'])->assertOk();
    expect($again->json('copied'))->toEqual(0)->and(FoodLog::count())->toEqual(4);
});

test('copy needs two different days', function () {
    $this->actingAs(eater())->postJson('/api/food-logs/copy', ['from' => '2026-10-14', 'to' => '2026-10-14'])->assertStatus(422);
});

// ---------------------------------------------------------------------------------------------
// Custom foods
// ---------------------------------------------------------------------------------------------

test('a user can add a custom food with servings and log it', function () {
    $user = eater();

    $res = $this->actingAs($user)->postJson('/api/foods', [
        'name' => '  Homemade granola bar ', 'brand' => 'Mine', 'diet_type' => 'vegetarian',
        'kcal_100g' => 420, 'protein_100g' => 10, 'carbs_100g' => 55, 'fat_100g' => 17, 'fibre_100g' => 6,
        'servings' => [['label' => '1 bar (40 g)', 'grams' => 40], ['label' => '2 bars (80 g)', 'grams' => 80]],
    ])->assertCreated();

    $food = Food::findOrFail($res->json('food.id'));
    expect($food->user_id)->toBe($user->id)->and($food->name)->toBe('Homemade granola bar')->and($food->is_public)->toBeFalse()
        ->and($food->name_search)->toBe('homemade granola bar')->and($food->servings)->toHaveCount(2)
        ->and($food->servings->first()->is_default)->toBeTrue()->and($res->json('food.mine'))->toBeTrue();
    expect(collect($this->actingAs($user)->getJson('/api/foods/search?q=granola&all=1')->json('foods'))->pluck('name'))->toContain('Homemade granola bar');
});

test('custom food numbers must make sense', function (array $override, string $field) {
    $base = ['name' => 'Test food', 'diet_type' => 'vegan', 'kcal_100g' => 200, 'protein_100g' => 10, 'carbs_100g' => 30, 'fat_100g' => 5];

    $this->actingAs(eater())->postJson('/api/foods', array_merge($base, $override))->assertStatus(422)->assertJsonValidationErrors($field);
    expect(Food::whereNotNull('user_id')->count())->toEqual(0);
})->with([
    'macros over 100 g' => [['protein_100g' => 60, 'carbs_100g' => 50], 'protein_100g'],
    'fibre above carbs' => [['fibre_100g' => 40], 'fibre_100g'],
    'calories far off the macros' => [['kcal_100g' => 20], 'kcal_100g'],
    'calories absurdly high' => [['kcal_100g' => 2000], 'kcal_100g'],
    'no name' => [['name' => ''], 'name'],
    'unknown diet type' => [['diet_type' => 'carnivore'], 'diet_type'],
    'bad serving' => [['servings' => [['label' => 'x', 'grams' => 0]]], 'servings.0.grams'],
]);

test('only the owner can delete a custom food and shared foods are untouchable', function () {
    $a = eater();
    $b = eater();
    $mine = $this->actingAs($a)->postJson('/api/foods', ['name' => 'Mine', 'diet_type' => 'vegan', 'kcal_100g' => 50, 'protein_100g' => 1, 'carbs_100g' => 10, 'fat_100g' => 1])->json('food.id');

    $this->actingAs($b)->deleteJson("/api/foods/{$mine}")->assertForbidden();
    $this->actingAs($a)->deleteJson('/api/foods/'.foodNamed('Banana')->id)->assertForbidden();
    $this->actingAs($a)->deleteJson("/api/foods/{$mine}")->assertNoContent();
    expect(Food::find($mine))->toBeNull();
});

test('the number of custom foods per user is limited', function () {
    $user = eater();
    foreach (range(1, 300) as $i) {
        Food::create(['user_id' => $user->id, 'name' => "F{$i}", 'name_search' => "f{$i}", 'category' => 'custom', 'diet_type' => 'vegan', 'kcal_100g' => 10, 'protein_100g' => 1, 'carbs_100g' => 1, 'fat_100g' => 0, 'is_public' => false]);
    }

    $this->actingAs($user)->postJson('/api/foods', ['name' => 'One more', 'diet_type' => 'vegan', 'kcal_100g' => 50, 'protein_100g' => 1, 'carbs_100g' => 10, 'fat_100g' => 1])->assertStatus(422);
});
