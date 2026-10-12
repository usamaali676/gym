<?php

use App\Models\Food;
use App\Models\FoodLog;
use App\Models\MembershipPlan;
use App\Models\SavedMeal;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    seedFoods();
    seedBadges();
    Carbon::setTestNow('2026-10-12 09:00:00');
    $this->user = User::factory()->onboarded()->create();
    $this->h = fn () => apiAs($this->user);
    $this->chicken = Food::whereNull('user_id')->where('name_search', 'like', '%chicken breast%')->first() ?? Food::whereNull('user_id')->where('protein_100g', '>', 20)->first();
});
afterEach(fn () => Carbon::setTestNow());

function customFood(array $o = []): array
{
    return $o + ['name' => 'Zorbex shake', 'diet_type' => 'vegetarian', 'kcal_100g' => 100, 'protein_100g' => 20, 'carbs_100g' => 5, 'fat_100g' => 0.5, 'fibre_100g' => 1, 'servings' => [['label' => '1 cup', 'grams' => 150]]];
}

describe('targets', function () {
    it('builds targets from the profile on first use and explains how', function () {
        $r = $this->getJson('/api/v1/nutrition/targets', ($this->h)())->assertOk();

        expect($r->json('data.plan.goal'))->toBe('recomposition')
            ->and($r->json('data.plan.training_targets.calories'))->toBeGreaterThan(1500)
            ->and($r->json('data.plan.training_targets.protein_g'))->toBeGreaterThan(100)
            ->and($r->json('data.plan.explain.bmr'))->toBeGreaterThan(1000)
            ->and($r->json('data.plan.is_stale'))->toBeFalse()
            ->and($r->json('data.week'))->toHaveCount(7);
    });

    it('recalculates when the goal changes and keeps the profile in step', function () {
        $this->getJson('/api/v1/nutrition/targets', ($this->h)());
        $before = $this->getJson('/api/v1/nutrition/targets', ($this->h)())->json('data.plan.training_targets.calories');

        $r = $this->postJson('/api/v1/nutrition/targets/recalculate', ['goal' => 'bulking', 'diet_preference' => 'vegan'], ($this->h)())->assertOk();

        expect($r->json('data.plan.goal'))->toBe('bulking')->and($r->json('data.plan.training_targets.calories'))->toBeGreaterThan($before)
            ->and($this->user->profile->fresh()->goal->value)->toBe('bulking')->and($this->user->profile->fresh()->diet_preference->value)->toBe('vegan');
        $this->postJson('/api/v1/nutrition/targets/recalculate', ['goal' => 'flying', 'diet_preference' => 'vegan'], ($this->h)())->assertStatus(422);
    });
});

describe('calculator', function () {
    it('150 g of a food with 20 g protein per 100 g is 30 g protein, worked out by the server', function () {
        $food = $this->postJson('/api/v1/foods', customFood(), ($this->h)())->assertCreated()->json('data');

        $r = $this->postJson('/api/v1/nutrition/calculate', ['food_id' => $food['id'], 'quantity' => 150, 'unit' => 'grams'], ($this->h)())->assertOk();

        expect($r->json('data.macros.protein_g'))->toBe(30)->and($r->json('data.macros.kcal'))->toBe(150)->and($r->json('data.grams'))->toBe(150)
            ->and($r->json('data.macros.carbs_g'))->toBe(7.5)->and($r->json('data.macros.fibre_g'))->toBe(1.5);
    });

    it('converts servings to grams on the server', function () {
        $food = $this->postJson('/api/v1/foods', customFood(), ($this->h)())->json('data');

        $r = $this->postJson('/api/v1/nutrition/calculate', ['food_id' => $food['id'], 'quantity' => 2, 'unit' => 'serving', 'serving_id' => $food['servings'][0]['id']], ($this->h)())->assertOk();

        expect($r->json('data.grams'))->toBe(300)->and($r->json('data.macros.protein_g'))->toBe(60)->and($r->json('data.serving_label'))->toBe('1 cup');
        $this->postJson('/api/v1/nutrition/calculate', ['food_id' => $food['id'], 'quantity' => 2, 'unit' => 'serving'], ($this->h)())->assertStatus(422);
        $this->postJson('/api/v1/nutrition/calculate', ['food_id' => $food['id'], 'quantity' => 9000, 'unit' => 'grams'], ($this->h)())->assertStatus(422);
    });

    it('stores exactly what the calculator returned, so app and server can never disagree', function () {
        $food = $this->postJson('/api/v1/foods', customFood(), ($this->h)())->json('data');
        $quote = $this->postJson('/api/v1/nutrition/calculate', ['food_id' => $food['id'], 'quantity' => 150, 'unit' => 'grams'], ($this->h)())->json('data.macros');

        $log = $this->postJson('/api/v1/food-logs', ['food_id' => $food['id'], 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 150, 'unit' => 'grams'], ($this->h)())->assertCreated()->json('data.log');

        expect($log['protein_g'])->toBe($quote['protein_g'])->and($log['kcal'])->toBe($quote['kcal'])->and($log['carbs_g'])->toBe($quote['carbs_g'])->and($log['fat_g'])->toBe($quote['fat_g']);
        expect(FoodLog::first()->protein_g)->toEqual(30.0);
    });
});

describe('foods', function () {
    it('searches the shared database, honouring the member\'s diet preference unless told otherwise', function () {
        $this->user->profile->update(['diet_preference' => 'vegan']);

        $filtered = $this->getJson('/api/v1/foods/search?q=chicken', ($this->h)())->assertOk();
        $all = $this->getJson('/api/v1/foods/search?q=chicken&all=1', ($this->h)())->assertOk();

        expect($filtered->json('meta.filtered_by_diet'))->toBe('vegan')->and($filtered->json('data'))->toBe([])
            ->and($all->json('data'))->not->toBeEmpty()->and($all->json('data.0.per_100g'))->toHaveKeys(['kcal', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g']);
        $this->getJson('/api/v1/foods/search', ($this->h)())->assertStatus(422);
    });

    it('creates private custom foods that nobody else can see, find or log', function () {
        $mine = $this->postJson('/api/v1/foods', customFood(), ($this->h)())->assertCreated()->json('data');
        $other = User::factory()->onboarded()->create();
        $h = apiAs($other);

        expect($mine['custom'])->toBeTrue()->and($mine['is_verified'])->toBeFalse();
        $this->getJson("/api/v1/foods/{$mine['id']}", $h)->assertNotFound();
        expect($this->getJson('/api/v1/foods/search?q=zorbex&all=1', $h)->json('data'))->toBe([]);
        $this->postJson('/api/v1/food-logs', ['food_id' => $mine['id'], 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'], $h)->assertNotFound();
        $this->deleteJson("/api/v1/foods/{$mine['id']}", [], $h)->assertNotFound();
        expect(Food::find($mine['id']))->not->toBeNull();
    });

    it('validates a custom food against its own macros', function () {
        $this->postJson('/api/v1/foods', customFood(['kcal_100g' => 900]), ($this->h)())->assertStatus(422)->assertJsonValidationErrorsAbsent ?? null;
        $this->postJson('/api/v1/foods', customFood(['protein_100g' => 90, 'carbs_g' => 50, 'carbs_100g' => 50]), ($this->h)())->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    });

    it('deleting a custom food keeps the meals already logged with it', function () {
        $food = $this->postJson('/api/v1/foods', customFood(), ($this->h)())->json('data');
        $this->postJson('/api/v1/food-logs', ['food_id' => $food['id'], 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'], ($this->h)());

        $this->deleteJson("/api/v1/foods/{$food['id']}", [], ($this->h)())->assertOk();

        $day = $this->getJson('/api/v1/nutrition/day?date=2026-10-12', ($this->h)())->assertOk();
        expect($day->json('data.totals.protein_g'))->toBe(20)->and(collect($day->json('data.meals'))->firstWhere('meal', 'lunch')['entries'][0]['name'])->toBe('Zorbex shake');
    });

    it('limits custom foods by the member\'s plan', function () {
        $free = MembershipPlan::default();
        $free->limits = ['custom_foods' => 1];
        $free->save();

        $this->postJson('/api/v1/foods', customFood(['name' => 'One']), ($this->h)())->assertCreated();
        $this->postJson('/api/v1/foods', customFood(['name' => 'Two']), ($this->h)())->assertForbidden()->assertJsonPath('error.code', 'limit_reached')->assertJsonPath('error.details.max', 1);
    });

    it('keeps a list of favourites per member', function () {
        $id = $this->chicken->id;

        $this->putJson("/api/v1/foods/{$id}/favorite", [], ($this->h)())->assertOk()->assertJsonPath('data.favorite', true);
        $this->putJson("/api/v1/foods/{$id}/favorite", [], ($this->h)())->assertOk();
        expect($this->getJson('/api/v1/foods/favorites', ($this->h)())->json('data'))->toHaveCount(1);
        expect($this->getJson('/api/v1/foods/favorites', apiAs(User::factory()->onboarded()->create()))->json('data'))->toBe([]);

        $this->deleteJson("/api/v1/foods/{$id}/favorite", [], ($this->h)())->assertOk()->assertJsonPath('data.favorite', false);
        expect($this->getJson('/api/v1/foods/favorites', ($this->h)())->json('data'))->toBe([]);
    });
});

describe('food log', function () {
    it('logs, edits and deletes entries and returns the day\'s refreshed totals', function () {
        $id = $this->chicken->id;
        $day = '2026-10-12';

        $created = $this->postJson('/api/v1/food-logs', ['food_id' => $id, 'logged_on' => $day, 'meal' => 'lunch', 'quantity' => 200, 'unit' => 'grams'], ($this->h)())->assertCreated();
        $logId = $created->json('data.log.id');
        expect($created->json('data.day.totals.calories'))->toBe($created->json('data.log.kcal'))->and($created->json('data.day.remaining.calories'))->toBeLessThan($created->json('data.day.targets.calories') ?? 99999);

        $edited = $this->patchJson("/api/v1/food-logs/{$logId}", ['quantity' => 100, 'unit' => 'grams', 'meal' => 'dinner'], ($this->h)())->assertOk();
        expect($edited->json('data.log.grams'))->toBe(100)->and($edited->json('data.log.meal'))->toBe('dinner')->and($edited->json('data.day.totals.calories'))->toBe($edited->json('data.log.kcal'));

        $this->deleteJson("/api/v1/food-logs/{$logId}", [], ($this->h)())->assertOk()->assertJsonPath('data.day.totals.calories', 0);
    });

    it('logs a whole meal at once and copies a day without doubling it', function () {
        $foods = Food::whereNull('user_id')->limit(3)->pluck('id');

        $this->postJson('/api/v1/food-logs/batch', ['logged_on' => '2026-10-11', 'meal' => 'breakfast', 'items' => $foods->map(fn ($id) => ['food_id' => $id, 'grams' => 100])->all()], ($this->h)())->assertCreated();
        $copy = $this->postJson('/api/v1/food-logs/copy', ['from' => '2026-10-11', 'to' => '2026-10-12'], ($this->h)())->assertOk();
        $again = $this->postJson('/api/v1/food-logs/copy', ['from' => '2026-10-11', 'to' => '2026-10-12'], ($this->h)())->assertOk();

        expect($copy->json('data.copied'))->toBe(3)->and($again->json('data.copied'))->toBe(0)->and($again->json('data.skipped'))->toBe(['Breakfast'])
            ->and(FoodLog::count())->toBe(6);
        $this->postJson('/api/v1/food-logs/copy', ['from' => '2026-10-12', 'to' => '2026-10-12'], ($this->h)())->assertStatus(422);
    });

    it('rejects dates outside the loggable window and bad amounts', function () {
        $id = $this->chicken->id;
        $log = fn (array $o) => $this->postJson('/api/v1/food-logs', $o + ['food_id' => $id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'], ($this->h)());

        $log(['logged_on' => '2024-01-01'])->assertStatus(422);
        $log(['logged_on' => '2026-12-01'])->assertStatus(422);
        $log(['quantity' => 0])->assertStatus(422);
        $log(['meal' => 'brunch'])->assertStatus(422);
        $log(['unit' => 'serving'])->assertStatus(422);
        $log(['food_id' => 999999])->assertStatus(422);
        expect(FoodLog::count())->toBe(0);
    });

    it('never lets one member touch another\'s entries', function () {
        $entry = $this->postJson('/api/v1/food-logs', ['food_id' => $this->chicken->id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'], ($this->h)())->json('data.log.id');
        $h = apiAs(User::factory()->onboarded()->create());

        $this->patchJson("/api/v1/food-logs/{$entry}", ['quantity' => 1, 'unit' => 'grams'], $h)->assertNotFound();
        $this->deleteJson("/api/v1/food-logs/{$entry}", [], $h)->assertNotFound();
        expect($this->getJson('/api/v1/nutrition/day?date=2026-10-12', $h)->json('data.totals.calories'))->toBe(0);
        expect(FoodLog::count())->toBe(1);
    });

    it('shows history and the day view with meals grouped', function () {
        $this->postJson('/api/v1/food-logs', ['food_id' => $this->chicken->id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'], ($this->h)());

        $history = $this->getJson('/api/v1/nutrition/history?days=7', ($this->h)())->assertOk()->json('data');
        $day = $this->getJson('/api/v1/nutrition/day', ($this->h)())->assertOk()->json('data');

        expect($history)->toHaveCount(7)->and(end($history)['calories'])->toBeGreaterThan(0)
            ->and(collect($day['meals'])->pluck('meal')->all())->toBe(['breakfast', 'lunch', 'snack', 'dinner'])
            ->and(collect($day['meals'])->firstWhere('meal', 'lunch')['entries'])->toHaveCount(1);
        $this->getJson('/api/v1/nutrition/history?days=500', ($this->h)())->assertStatus(422);
    });

    it('suggests a meal plan that fits the targets', function () {
        $plan = $this->getJson('/api/v1/nutrition/meal-plan', ($this->h)())->assertOk()->json('data');

        expect($plan)->not->toBeEmpty()->and($plan[0])->toHaveKeys(['slot', 'target_kcal', 'meal', 'alternatives']);
    });
});

describe('saved meals', function () {
    it('saves a meal, recomputes macros from the foods and logs it on a day', function () {
        $foods = Food::whereNull('user_id')->limit(2)->get();

        $id = $this->postJson('/api/v1/saved-meals', ['name' => 'Usual breakfast', 'meal' => 'breakfast', 'items' => [['food_id' => $foods[0]->id, 'grams' => 100], ['food_id' => $foods[1]->id, 'grams' => 50]]], ($this->h)())->assertCreated()->json('data.id');
        $list = $this->getJson('/api/v1/saved-meals', ($this->h)())->assertOk()->json('data');
        expect($list)->toHaveCount(1)->and($list[0]['items'])->toHaveCount(2)
            ->and($list[0]['totals']['kcal'])->toEqual(round($foods[0]->kcal_100g + $foods[1]->kcal_100g / 2, 1));

        $applied = $this->postJson("/api/v1/saved-meals/{$id}/log", ['logged_on' => '2026-10-12'], ($this->h)())->assertCreated();
        expect($applied->json('data.logs'))->toHaveCount(2)->and($applied->json('data.logs.0.meal'))->toBe('breakfast');
        $this->postJson("/api/v1/saved-meals/{$id}/log", ['logged_on' => '2026-10-12', 'meal' => 'dinner'], ($this->h)())->assertCreated();
        expect(FoodLog::where('meal', 'dinner')->count())->toBe(2);
    });

    it('is private to its owner and validates what goes in', function () {
        $food = Food::whereNull('user_id')->first();
        $id = $this->postJson('/api/v1/saved-meals', ['name' => 'Mine', 'items' => [['food_id' => $food->id, 'grams' => 100]]], ($this->h)())->assertCreated()->json('data.id');
        $h = apiAs(User::factory()->onboarded()->create());

        expect($this->getJson('/api/v1/saved-meals', $h)->json('data'))->toBe([]);
        $this->deleteJson("/api/v1/saved-meals/{$id}", [], $h)->assertNotFound();
        $this->postJson("/api/v1/saved-meals/{$id}/log", ['logged_on' => '2026-10-12', 'meal' => 'lunch'], $h)->assertNotFound();
        $this->postJson("/api/v1/saved-meals/{$id}/log", ['logged_on' => '2026-10-12'], ($this->h)())->assertStatus(422); // no slot given and none saved
        $this->postJson('/api/v1/saved-meals', ['name' => 'Bad', 'items' => [['food_id' => 999999, 'grams' => 100]]], ($this->h)())->assertStatus(422);
        $this->postJson('/api/v1/saved-meals', ['name' => 'Empty', 'items' => []], ($this->h)())->assertStatus(422);

        $private = $this->postJson('/api/v1/foods', customFood(), $h)->json('data');
        $this->postJson('/api/v1/saved-meals', ['name' => 'Sneaky', 'items' => [['food_id' => $private['id'], 'grams' => 100]]], ($this->h)())->assertStatus(422);
        $this->deleteJson("/api/v1/saved-meals/{$id}", [], ($this->h)())->assertOk();
        expect(SavedMeal::count())->toBe(0);
    });
});

describe('habits and water', function () {
    it('tracks water, creatine, sleep and mobility with server-side goals and XP', function () {
        $state = $this->getJson('/api/v1/habits', ($this->h)())->assertOk()->json('data');
        expect($state['water']['goal_ml'])->toBeGreaterThan(1500)->and($state['water']['total_ml'])->toBe(0);

        $water = $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], ($this->h)())->assertOk();
        expect($water->json('data.water.total_ml'))->toBe(500);
        $this->deleteJson('/api/v1/habits/water/last', [], ($this->h)())->assertOk()->assertJsonPath('data.water.total_ml', 0);

        $this->putJson('/api/v1/habits/creatine', ['taken' => true], ($this->h)())->assertOk()->assertJsonPath('data.creatine.taken', true);
        $this->putJson('/api/v1/habits/sleep', ['sleep_hours' => 8, 'sleep_quality' => 4, 'bedtime' => '23:00', 'wake_time' => '07:00'], ($this->h)())->assertOk()->assertJsonPath('data.sleep.hours', 8);
        $this->putJson('/api/v1/habits/mobility', ['minutes' => 12], ($this->h)())->assertOk()->assertJsonPath('data.mobility_minutes', 12);
        $this->putJson('/api/v1/habits/sleep', ['sleep_hours' => 40], ($this->h)())->assertStatus(422);
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 5], ($this->h)())->assertStatus(422);
    });

    it('only edits the last seven days', function () {
        $this->putJson('/api/v1/habits/creatine?date=2026-10-08', ['taken' => true], ($this->h)())->assertOk();
        $this->putJson('/api/v1/habits/creatine?date=2026-09-01', ['taken' => true], ($this->h)())->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->putJson('/api/v1/habits/creatine?date=2026-10-20', ['taken' => true], ($this->h)())->assertStatus(422);
    });
});

describe('membership gate', function () {
    it('closes nutrition (including water) when the plan does not include it, but keeps creatine free', function () {
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['nutrition' => 1]);
        $free->save();

        foreach (['/api/v1/nutrition/targets', '/api/v1/nutrition/day', '/api/v1/foods/search?q=egg', '/api/v1/saved-meals', '/api/v1/foods/favorites'] as $url) {
            $this->getJson($url, ($this->h)())->assertForbidden()->assertJsonPath('error.details.required_entitlement', 'nutrition');
        }
        $this->postJson('/api/v1/food-logs', [], ($this->h)())->assertForbidden();
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 250], ($this->h)())->assertForbidden();
        $this->putJson('/api/v1/habits/creatine', ['taken' => true], ($this->h)())->assertOk();
        $this->getJson('/api/v1/dashboard', ($this->h)())->assertOk()->assertJsonPath('data.nutrition', null);
    });
});
