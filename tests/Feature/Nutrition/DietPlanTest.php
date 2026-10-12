<?php

use App\Enums\DietPreference;
use App\Enums\Goal;
use App\Models\DietPlan;
use App\Models\Food;
use App\Models\User;
use App\Services\DietCalculatorService;
use App\Services\DietPlanService;
use App\Services\MealPlanService;
use App\Services\NutritionDayService;
use App\Services\ProteinGapService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    seedFoods();
    Carbon::setTestNow('2026-10-14 12:00:00'); // Wednesday
});
afterEach(fn () => Carbon::setTestNow());

function dieter(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create()->load('profile');
}

// ---------------------------------------------------------------------------------------------
// Plan building
// ---------------------------------------------------------------------------------------------

test('a plan is created from the profile on first use, with the calculator numbers', function () {
    $user = dieter(['sex' => 'male', 'age' => 30, 'height_cm' => 180, 'weight_kg' => 80, 'activity_level' => 'moderate', 'goal' => 'cutting', 'days_per_week' => 5]);

    $plan = app(DietPlanService::class)->ensureActive($user);

    expect($plan)->toBeInstanceOf(DietPlan::class)
        ->and($plan->bmr)->toBe(1780)->and($plan->tdee)->toBe(2759)->and($plan->calories)->toBe(2207)
        ->and($plan->protein_g)->toBe(184)->and($plan->is_active)->toBeTrue()
        ->and($plan->formula_version)->toBe('msj-1')
        ->and($plan->goal)->toBe(Goal::Cutting);
    expect(app(DietPlanService::class)->ensureActive($user)->id)->toBe($plan->id);   // no duplicate
});

test('rebuilding keeps the old plan as inactive history', function () {
    $user = dieter();
    $service = app(DietPlanService::class);
    $first = $service->build($user);
    $user->profile->update(['weight_kg' => 85]);

    $second = $service->build($user->fresh('profile'));

    expect($first->fresh()->is_active)->toBeFalse()->and($second->is_active)->toBeTrue()
        ->and(DietPlan::where('user_id', $user->id)->where('is_active', true)->count())->toBe(1)
        ->and($second->weight_kg)->toBe(85.0);
});

test('calorie cycling gives rest days lower targets and keeps the weekly total', function () {
    $user = dieter(['days_per_week' => 5]);
    $plan = app(DietPlanService::class)->build($user, true);

    expect($plan->calorie_cycling)->toBeTrue()
        ->and($plan->targetsFor(false)['calories'])->toBeLessThan($plan->targetsFor(true)['calories'])
        ->and($plan->targetsFor(true))->toBe(['calories' => $plan->calories, 'protein_g' => $plan->protein_g, 'carbs_g' => $plan->carbs_g, 'fat_g' => $plan->fat_g, 'fibre_g' => $plan->fibre_g]);

    $weekly = 5 * $plan->targetsFor(true)['calories'] + 2 * $plan->targetsFor(false)['calories'];
    $base = app(DietCalculatorService::class)->calculate([
        'sex' => $user->profile->sex, 'age' => $user->profile->age, 'height_cm' => $user->profile->height_cm, 'weight_kg' => $user->profile->weight_kg,
        'activity_level' => $user->profile->activity_level, 'goal' => $user->profile->goal, 'training_days_per_week' => 5,
    ])['calories'];
    expect(abs($weekly - 7 * $base))->toBeLessThanOrEqual(3);
});

test('without cycling every day has the same target', function () {
    $plan = app(DietPlanService::class)->build(dieter());

    expect($plan->targetsFor(false))->toBe($plan->targetsFor(true));
});

test('the plan is stale when the profile changes and fresh again after a rebuild', function () {
    $user = dieter();
    $service = app(DietPlanService::class);
    $plan = $service->build($user);
    expect($service->isStale($plan, $user->profile))->toBeFalse();

    $user->profile->update(['weight_kg' => 90]);
    expect($service->isStale($plan->fresh(), $user->fresh('profile')->profile))->toBeTrue();

    $new = $service->build($user->fresh('profile'));
    expect($service->isStale($new, $user->fresh('profile')->profile))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------------------------

test('the diet page shows targets, the calculation and the week', function () {
    $user = dieter(['goal' => 'maintenance', 'sex' => 'male', 'age' => 30, 'height_cm' => 180, 'weight_kg' => 80, 'activity_level' => 'moderate']);

    $this->actingAs($user)->get('/diet')->assertOk()
        ->assertSee('Your daily targets')
        ->assertSee('2,759')                      // calories
        ->assertSee('How these numbers are calculated')
        ->assertSee('Mifflin-St Jeor')
        ->assertSee('This week')
        ->assertSee('A day of eating that fits')
        ->assertSee('Training day target');
    expect(DietPlan::where('user_id', $user->id)->count())->toBe(1);
});

test('the diet page offers a recalculation when the profile changed', function () {
    $user = dieter();
    $this->actingAs($user)->get('/diet')->assertOk()->assertDontSee('Your profile changed since');

    $user->profile->update(['weight_kg' => 92]);

    $this->actingAs($user)->get('/diet')->assertOk()->assertSee('Your profile changed since');
});

test('recalculating updates the profile goal, diet and the plan', function () {
    $user = dieter(['goal' => 'maintenance', 'diet_preference' => 'nonveg']);
    $this->actingAs($user)->get('/diet');

    $this->actingAs($user)->post('/diet/recalculate', ['goal' => 'cutting', 'diet_preference' => 'vegetarian', 'calorie_cycling' => '1'])
        ->assertRedirect(route('diet.index'));

    $plan = DietPlan::where('user_id', $user->id)->where('is_active', true)->firstOrFail();
    expect($plan->goal)->toBe(Goal::Cutting)->and($plan->diet_preference->value)->toBe('vegetarian')->and($plan->calorie_cycling)->toBeTrue()
        ->and($user->profile->fresh()->goal)->toBe(Goal::Cutting)
        ->and(DietPlan::where('user_id', $user->id)->count())->toBe(2);

    $this->actingAs($user)->get('/diet')->assertOk()->assertSee('Calorie cycling targets', false)->assertSee('Rest days eat 12% less');
});

test('recalculating validates its input', function () {
    $user = dieter();

    $this->actingAs($user)->post('/diet/recalculate', ['goal' => 'bulk-like-hell', 'diet_preference' => 'nonveg'])->assertSessionHasErrors('goal');
    $this->actingAs($user)->post('/diet/recalculate', ['goal' => 'cutting', 'diet_preference' => 'carnivore'])->assertSessionHasErrors('diet_preference');
});

test('diet and food pages need a login', function () {
    $this->get('/diet')->assertRedirect('/login');
    $this->get('/food')->assertRedirect('/login');
    $this->post('/diet/recalculate')->assertRedirect('/login');
});

test('the dashboard shows the new diet targets', function () {
    $user = dieter();
    app(DietPlanService::class)->build($user);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Set your diet targets');
});

test('the food page embeds the day, the targets and the urls it needs', function () {
    $user = dieter();
    $this->actingAs($user)->get('/diet');

    $page = $this->actingAs($user)->get('/food')->assertOk()->assertSee('Food log')->assertSee('Add food')->assertSee('Copy yesterday');
    preg_match('#id="food-config">(.*?)</script>#s', $page->getContent(), $m);
    $config = json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);

    expect($config['date'])->toBe('2026-10-14')->and($config['hasPlan'])->toBeTrue()
        ->and($config['meals'])->toBe(['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snack' => 'Snack', 'dinner' => 'Dinner'])
        ->and($config['day']['targets']['calories'])->toBeGreaterThan(1500)
        ->and($config['history'])->toHaveCount(14)
        ->and($config['urls']['search'])->toEndWith('/api/foods/search');
});

test('the food page shows entries for the requested day and clamps silly dates', function () {
    $user = dieter();
    $food = Food::where('name', 'Banana')->firstOrFail();
    $this->actingAs($user)->postJson('/api/food-logs', ['food_id' => $food->id, 'logged_on' => '2026-10-13', 'meal' => 'snack', 'quantity' => 100, 'unit' => 'grams'])->assertCreated();

    $this->actingAs($user)->get('/food?date=2026-10-13')->assertOk()->assertSee('Yesterday')->assertSee('Banana');
    $this->actingAs($user)->get('/food?date=2026-10-14')->assertOk()->assertSee('Today');
    $this->actingAs($user)->get('/food?date=1999-01-01')->assertOk();
    $this->actingAs($user)->get('/food?date=2099-01-01')->assertOk()->assertSee('Tomorrow');
    $this->actingAs($user)->get('/food?date=garbage')->assertOk()->assertSee('Today');
});

test('another users entries never show on my page', function () {
    $a = dieter();
    $b = dieter();
    $food = Food::where('name', 'Paneer')->firstOrFail();
    $this->actingAs($a)->postJson('/api/food-logs', ['food_id' => $food->id, 'logged_on' => '2026-10-14', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'])->assertCreated();

    $page = $this->actingAs($b)->get('/food')->assertOk();
    preg_match('#id="food-config">(.*?)</script>#s', $page->getContent(), $m);

    expect(json_decode(html_entity_decode($m[1]), true)['logs'])->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Meal plan and protein gap
// ---------------------------------------------------------------------------------------------

test('a day of meals has four slots, each with real alternatives', function (string $diet) {
    $user = dieter(['diet_preference' => $diet, 'goal' => 'maintenance']);
    $plan = app(DietPlanService::class)->build($user);

    $day = app(MealPlanService::class)->suggestDay($plan, true);

    expect($day)->toHaveCount(4)->and(array_column($day, 'slot'))->toBe(['breakfast', 'lunch', 'snack', 'dinner']);
    foreach ($day as $slot) {
        // vegans choose from the 3 vegan meals per slot, everyone else also from the less strict ones
        $alternatives = $diet === 'vegan' ? 2 : 3;
        expect($slot['meal']['items'])->not->toBeEmpty()->and($slot['alternatives'])->toHaveCount($alternatives);
        $names = collect($slot['alternatives'])->pluck('name')->push($slot['meal']['name']);
        expect($names->unique())->toHaveCount($alternatives + 1);
    }
})->with(['nonveg', 'eggetarian', 'vegetarian', 'vegan']);

test('suggested meals add up to roughly the days targets', function (string $goal) {
    $user = dieter(['goal' => $goal, 'diet_preference' => 'nonveg']);
    $plan = app(DietPlanService::class)->build($user);

    $day = app(MealPlanService::class)->suggestDay($plan, true);
    $kcal = collect($day)->sum('meal.kcal');
    $protein = collect($day)->sum('meal.protein_g');

    expect($kcal)->toBeBetween((int) ($plan->calories * 0.8), (int) ($plan->calories * 1.2));
    expect($protein)->toBeGreaterThan($plan->protein_g * 0.6);
})->with(['cutting', 'maintenance', 'bulking']);

test('scaling follows the targets: a bulk gets bigger portions than a cut', function () {
    $cut = app(MealPlanService::class)->suggestDay(app(DietPlanService::class)->build(dieter(['goal' => 'cutting', 'diet_preference' => 'vegetarian'])), true);
    $bulk = app(MealPlanService::class)->suggestDay(app(DietPlanService::class)->build(dieter(['goal' => 'bulking', 'diet_preference' => 'vegetarian'])), true);

    expect(collect($bulk)->sum('meal.kcal'))->toBeGreaterThan(collect($cut)->sum('meal.kcal'));
});

test('a vegan plan never contains animal foods', function () {
    $user = dieter(['diet_preference' => 'vegan']);
    $day = app(MealPlanService::class)->suggestDay(app(DietPlanService::class)->build($user), true);
    $foods = Food::pluck('diet_type', 'id');

    foreach ($day as $slot) {
        foreach (array_merge([$slot['meal']], $slot['alternatives']) as $meal) {
            foreach ($meal['items'] as $item) {
                expect($foods[$item['food_id']])->toBe('vegan', "{$meal['name']}: {$item['name']}");
            }
        }
    }
});

test('rest days with cycling get smaller meals', function () {
    $user = dieter(['diet_preference' => 'nonveg', 'days_per_week' => 5]);
    $plan = app(DietPlanService::class)->build($user, true);
    $service = app(MealPlanService::class);

    expect(collect($service->suggestDay($plan, false))->sum('meal.kcal'))->toBeLessThan(collect($service->suggestDay($plan, true))->sum('meal.kcal'));
});

test('protein suggestions respect the diet, the remaining calories and are protein dense', function () {
    $user = dieter();
    $gaps = app(ProteinGapService::class);

    $veg = $gaps->suggest($user, 40, 800, DietPreference::Vegan);
    expect($veg)->not->toBeEmpty();
    $types = Food::whereIn('id', array_column($veg, 'food_id'))->pluck('diet_type')->unique()->all();
    expect($types)->toBe(['vegan']);

    $nonveg = $gaps->suggest($user, 40, 800, DietPreference::NonVeg);
    foreach ($nonveg as $s) {
        expect($s['kcal'])->toBeLessThanOrEqual(860)->and($s['protein_g'])->toBeGreaterThanOrEqual(6);
    }
    expect(collect($nonveg)->pluck('name')->implode(' '))->not->toContain('Peanut butter')->not->toContain('Almonds');
});

test('no protein hint for a small gap and only fitting portions when calories are nearly used up', function () {
    $user = dieter();
    $gaps = app(ProteinGapService::class);

    expect($gaps->suggest($user, 10, 500, DietPreference::NonVeg))->toBe([]);
    $tight = $gaps->suggest($user, 30, 90, DietPreference::NonVeg);
    foreach ($tight as $s) {
        expect($s['kcal'])->toBeLessThanOrEqual(150);
    }
});

test('the 14 day history includes today and each days totals', function () {
    $user = dieter(['goal' => 'maintenance']);
    $banana = Food::where('name', 'Banana')->firstOrFail();
    foreach (['2026-10-14' => 200, '2026-10-13' => 100, '2026-10-01' => 100, '2026-09-25' => 100] as $date => $grams) {
        $this->actingAs($user)->postJson('/api/food-logs', ['food_id' => $banana->id, 'logged_on' => $date, 'meal' => 'snack', 'quantity' => $grams, 'unit' => 'grams'])->assertCreated();
    }

    app(DietPlanService::class)->build($user);
    $history = collect(app(NutritionDayService::class)->history($user->load('profile'), 14))->keyBy('date');

    expect($history)->toHaveCount(14)
        ->and($history['2026-10-14']['calories'])->toEqual(178)         // today: 2 x 89 kcal
        ->and($history['2026-10-13']['calories'])->toEqual(89)
        ->and($history['2026-10-01']['calories'])->toEqual(89)         // first day of the window
        ->and($history->has('2026-09-25'))->toBeFalse()               // 15 days ago is outside it
        ->and($history['2026-10-14']['target_calories'])->toBeGreaterThan(1500);
});
