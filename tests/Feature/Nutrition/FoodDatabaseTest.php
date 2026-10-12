<?php

use App\Enums\DietPreference;
use App\Models\Food;
use App\Models\FoodServing;
use App\Models\MealTemplate;
use App\Models\User;
use Database\Seeders\FoodSeeder;

beforeEach(fn () => seedFoods());

test('the food database has 150+ foods, each with servings and plausible energy', function () {
    $foods = Food::with('servings')->whereNull('user_id')->get();

    expect($foods->count())->toBeGreaterThanOrEqual(150);

    foreach ($foods as $f) {
        expect($f->servings)->not->toBeEmpty("{$f->name} has no servings")
            ->and($f->servings->where('is_default', true))->toHaveCount(1, "{$f->name} needs exactly one default serving")
            ->and($f->is_public)->toBeTrue()
            ->and(DietPreference::tryFrom($f->diet_type))->not->toBeNull();

        // Energy is within 12% (+/-12 kcal) of the Atwater estimate, with or without fibre counted as 2 kcal/g.
        $strict = 4 * $f->protein_100g + 4 * ($f->carbs_100g - $f->fibre_100g) + 2 * $f->fibre_100g + 9 * $f->fat_100g;
        $loose = 4 * $f->protein_100g + 4 * $f->carbs_100g + 9 * $f->fat_100g;
        expect($f->kcal_100g)->toBeGreaterThanOrEqual(min($strict, $loose) * 0.88 - 12, $f->name)
            ->toBeLessThanOrEqual(max($strict, $loose) * 1.12 + 12, $f->name);
        expect($f->protein_100g + $f->carbs_100g + $f->fat_100g)->toBeLessThanOrEqual(100.5, "{$f->name}: macros exceed 100 g");
        expect($f->fibre_100g)->toBeLessThanOrEqual($f->carbs_100g + 0.5);
    }
});

test('food names are unique and cover every diet type and the main staples', function () {
    $names = Food::whereNull('user_id')->pluck('name');
    expect($names->map(fn ($n) => mb_strtolower($n))->unique())->toHaveCount($names->count());

    foreach (['nonveg', 'eggetarian', 'vegetarian', 'vegan'] as $type) {
        expect(Food::where('diet_type', $type)->count())->toBeGreaterThan(3, $type);
    }
    foreach (['Chicken breast, cooked', 'Paneer', 'Roti / chapati, whole wheat', 'White rice, cooked', 'Whey protein powder', 'Banana', 'Egg, whole, boiled', 'Moong dal, cooked'] as $staple) {
        expect($names)->toContain($staple);
    }
});

test('seeding twice does not duplicate foods or servings, and leaves user foods alone', function () {
    $user = User::factory()->create();
    Food::create(['user_id' => $user->id, 'name' => 'My shake', 'name_search' => 'my shake', 'category' => 'custom', 'diet_type' => 'vegan', 'kcal_100g' => 80, 'protein_100g' => 10, 'carbs_100g' => 5, 'fat_100g' => 1, 'is_public' => false]);
    $count = Food::count();
    $servings = FoodServing::count();

    $this->seed(FoodSeeder::class);

    expect(Food::count())->toBe($count)->and(FoodServing::count())->toBe($servings)
        ->and(Food::where('name', 'My shake')->exists())->toBeTrue();
});

test('meal templates: 12 per diet type, 3 for each meal, macros computed from the foods', function () {
    expect(MealTemplate::count())->toBe(48);

    foreach (DietPreference::cases() as $diet) {
        foreach (['breakfast', 'lunch', 'snack', 'dinner'] as $slot) {
            expect(MealTemplate::where(['diet_preference' => $diet->value, 'slot' => $slot])->count())->toBe(3, "{$diet->value} {$slot}");
        }
    }

    $foods = Food::all()->keyBy('id');
    foreach (MealTemplate::all() as $t) {
        $kcal = collect($t->items)->sum(fn ($i) => $foods[$i['food_id']]->kcal_100g * $i['grams'] / 100);
        expect($t->kcal)->toBe((int) round($kcal), $t->name)->and($t->kcal)->toBeBetween(150, 750, $t->name);
        // Each meal gives a real contribution of protein
        expect($t->protein_g)->toBeGreaterThan(7, $t->name);
    }
});

test('no template contains a food its diet type forbids', function () {
    $foods = Food::all()->keyBy('id');

    foreach (MealTemplate::all() as $t) {
        foreach ($t->items as $item) {
            expect(DietPreference::from($foods[$item['food_id']]->diet_type)->rank())->toBeLessThanOrEqual($t->diet_preference->rank(), "{$t->name}: {$item['name']}");
        }
    }
    // Spot check the promise: vegan meals have no dairy, egg, meat or honey.
    $vegan = MealTemplate::where('diet_preference', 'vegan')->get()->flatMap(fn ($t) => collect($t->items)->pluck('name'));
    expect($vegan->filter(fn ($n) => preg_match('/paneer|egg|milk, (whole|toned)|curd|chicken|honey|whey|ghee/i', $n)))->toBeEmpty();
});

test('diet preference ranks nest: vegan < vegetarian < eggetarian < non-veg', function () {
    expect(DietPreference::Vegan->allowedFoodTypes())->toBe(['vegan'])
        ->and(DietPreference::Vegetarian->allowedFoodTypes())->toEqualCanonicalizing(['vegan', 'vegetarian'])
        ->and(DietPreference::Eggetarian->allowedFoodTypes())->toEqualCanonicalizing(['vegan', 'vegetarian', 'eggetarian'])
        ->and(DietPreference::NonVeg->allowedFoodTypes())->toHaveCount(4);
});
