<?php

use App\Models\Exercise;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Models\PostureRule;
use App\Models\PostureSession;
use App\Models\ProgressPhoto;
use App\Models\SessionExercise;
use App\Models\User;
use App\Models\WorkoutSession;
use Database\Seeders\PostureRuleSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPosture();
    seedFoods();
});

function admin(): User
{
    return User::factory()->onboarded()->admin()->create();
}

function member(): User
{
    return User::factory()->onboarded()->create();
}

// ---------------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------------

test('every admin page is closed to members and visitors', function (string $url) {
    $this->get($url)->assertRedirect('/login');
    $this->actingAs(member())->get($url)->assertForbidden();
})->with(['/admin', '/admin/users', '/admin/plan', '/admin/exercises', '/admin/exercises/create', '/admin/posture', '/admin/foods', '/admin/foods/create']);

test('members cannot change anything through admin routes', function () {
    $m = member();
    $exercise = Exercise::first();
    $food = Food::whereNull('user_id')->first();
    $day = PlanDay::first();

    $this->actingAs($m)->patch("/admin/users/{$m->id}/role", ['role' => 'admin'])->assertForbidden();
    $this->actingAs($m)->post('/admin/exercises', [])->assertForbidden();
    $this->actingAs($m)->delete("/admin/exercises/{$exercise->slug}")->assertForbidden();
    $this->actingAs($m)->patch("/admin/foods/{$food->id}", [])->assertForbidden();
    $this->actingAs($m)->put("/admin/posture/{$exercise->slug}", [])->assertForbidden();
    $this->actingAs($m)->patch("/admin/plan/days/{$day->id}", [])->assertForbidden();
    expect($m->fresh()->role->value)->toBe('user');
});

test('the overview shows counts and honest system checks', function () {
    $this->actingAs(admin())->get('/admin')->assertOk()->assertSee('Control room')->assertSee('System checks')->assertSee('What admins can and cannot see');
});

// ---------------------------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------------------------

test('the user list searches and filters', function () {
    $a = admin();
    User::factory()->create(['name' => 'Zainab Findme', 'email' => 'zainab@example.com']);
    User::factory()->guest()->create();

    $this->actingAs($a)->get('/admin/users?q=Findme')->assertOk()->assertSee('Zainab Findme')->assertDontSee('Guest #');
    $this->actingAs($a)->get('/admin/users?filter=guests')->assertOk()->assertSee('Guest #')->assertDontSee('Zainab');
    $this->actingAs($a)->get('/admin/users?filter=admins')->assertOk()->assertSee($a->name);
});

test('the user page shows counts only, never private content', function () {
    $a = admin();
    $u = member();
    ProgressPhoto::create(['user_id' => $u->id, 'taken_on' => '2026-10-01', 'pose' => 'front', 'path' => 'progress/x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1, 'notes' => 'my secret note']);

    $this->actingAs($a)->get("/admin/users/{$u->id}")->assertOk()->assertSee('Progress photos')->assertDontSee('my secret note')->assertDontSee('progress/x.jpg');
});

test('an admin can promote and demote, but not themselves, guests or the last admin', function () {
    $a = admin();
    $b = member();

    $this->actingAs($a)->patch("/admin/users/{$b->id}/role", ['role' => 'admin'])->assertSessionHasNoErrors();
    expect($b->fresh()->role->value)->toBe('admin');

    $this->actingAs($a)->patch("/admin/users/{$a->id}/role", ['role' => 'user'])->assertSessionHasErrors('role');
    expect($a->fresh()->role->value)->toBe('admin');

    $this->actingAs($a)->patch("/admin/users/{$b->id}/role", ['role' => 'user'])->assertSessionHasNoErrors();
    expect($b->fresh()->role->value)->toBe('user');

    $guest = User::factory()->guest()->create();
    $this->actingAs($a)->patch("/admin/users/{$guest->id}/role", ['role' => 'admin'])->assertSessionHasErrors('role');
    $this->actingAs($a)->patch("/admin/users/{$b->id}/role", ['role' => 'superuser'])->assertSessionHasErrors('role');
});

test('the last admin cannot be demoted by another account that lost its rights', function () {
    $only = admin();
    $second = admin();
    $this->actingAs($second)->patch("/admin/users/{$only->id}/role", ['role' => 'user'])->assertSessionHasNoErrors();   // two admins: fine
    // now $second is the only admin; demoting them from a stale session must fail.
    $this->actingAs($only->fresh()->forceFill(['role' => 'admin']))->patch("/admin/users/{$second->id}/role", ['role' => 'user'])->assertSessionHasErrors('role');
    expect($second->fresh()->role->value)->toBe('admin');
});

test('deleting an account needs the exact confirmation and removes the data', function () {
    $a = admin();
    $u = member();
    WorkoutSession::create(['user_id' => $u->id, 'scheduled_for' => '2026-10-05', 'status' => 'completed', 'client_uuid' => (string) Str::uuid()]);

    $this->actingAs($a)->delete("/admin/users/{$u->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    expect(User::whereKey($u->id)->exists())->toBeTrue();

    $this->actingAs($a)->delete("/admin/users/{$u->id}", ['confirm' => strtoupper($u->email)])->assertRedirect('/admin/users');
    expect(User::whereKey($u->id)->exists())->toBeFalse()->and(WorkoutSession::count())->toBe(0);

    $this->actingAs($a)->delete("/admin/users/{$a->id}", ['confirm' => $a->email])->assertSessionHasErrors('confirm');
    expect(User::whereKey($a->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Plan editor
// ---------------------------------------------------------------------------------------------

test('the plan editor lists all seven days and opens each one', function () {
    $a = admin();
    $this->actingAs($a)->get('/admin/plan')->assertOk()->assertSee('Monday');
    foreach (PlanDay::all() as $day) {
        $this->actingAs($a)->get("/admin/plan/days/{$day->id}")->assertOk();
    }
});

test('day details can be edited and bump the plan version', function () {
    $a = admin();
    $day = PlanDay::where('is_rest', false)->first();
    $before = Plan::default()->version;

    $this->actingAs($a)->patch("/admin/plan/days/{$day->id}", [
        'name' => $day->name, 'session_type' => 'push', 'label' => 'PUSH', 'focus' => 'Chest and Triceps', 'is_rest' => 0, 'description' => 'New text', 'recovery_note' => null,
    ])->assertSessionHasNoErrors();

    expect($day->fresh()->focus)->toBe('Chest and Triceps')->and(Plan::default()->version)->toBe($before + 1);
    $this->actingAs($a)->patch("/admin/plan/days/{$day->id}", ['name' => '', 'session_type' => 'bogus', 'label' => 'x', 'focus' => 'x', 'is_rest' => 0])->assertSessionHasErrors(['name', 'session_type']);
});

test('exercises can be added, edited, reordered and removed, keeping positions contiguous', function () {
    $a = admin();
    $day = PlanDay::where('is_rest', false)->with('items')->first();
    $count = $day->items->count();
    $newExercise = Exercise::whereNotIn('id', $day->items->pluck('exercise_id'))->where('is_active', true)->first();

    $this->actingAs($a)->post("/admin/plan/days/{$day->id}/items", ['exercise_id' => $newExercise->id, 'sets' => 4, 'reps_min' => 6, 'reps_max' => 8, 'focus' => 'Test focus'])->assertSessionHasNoErrors();
    $item = PlanDayExercise::where('plan_day_id', $day->id)->where('exercise_id', $newExercise->id)->firstOrFail();
    expect($item->position)->toBe($count + 1);

    // the same exercise twice on one day is rejected
    $this->actingAs($a)->post("/admin/plan/days/{$day->id}/items", ['exercise_id' => $newExercise->id, 'sets' => 3, 'reps_min' => 8, 'reps_max' => 10, 'focus' => 'dup'])->assertSessionHasErrors('exercise_id');

    $this->actingAs($a)->patch("/admin/plan/items/{$item->id}", ['exercise_id' => $newExercise->id, 'sets' => 5, 'reps_min' => 5, 'reps_max' => 5, 'focus' => 'Heavy', 'rest_seconds_override' => 180])->assertSessionHasNoErrors();
    expect($item->fresh()->sets)->toBe(5)->and($item->fresh()->rest_seconds_override)->toBe(180);
    $this->actingAs($a)->patch("/admin/plan/items/{$item->id}", ['exercise_id' => $newExercise->id, 'sets' => 5, 'reps_min' => 9, 'reps_max' => 5, 'focus' => 'bad'])->assertSessionHasErrors('reps_max');

    $this->actingAs($a)->post("/admin/plan/items/{$item->id}/move", ['direction' => 'up']);
    expect($item->fresh()->position)->toBe($count);
    $this->actingAs($a)->post("/admin/plan/items/{$item->id}/move", ['direction' => 'down']);
    expect($item->fresh()->position)->toBe($count + 1);

    $first = PlanDayExercise::where('plan_day_id', $day->id)->orderBy('position')->first();
    $this->actingAs($a)->delete("/admin/plan/items/{$first->id}")->assertSessionHasNoErrors();
    expect(PlanDayExercise::where('plan_day_id', $day->id)->orderBy('position')->pluck('position')->all())->toBe(range(1, $count));
});

test('removing a plan row keeps workout history intact', function () {
    $a = admin();
    $u = member();
    $item = PlanDayExercise::first();
    $session = WorkoutSession::create(['user_id' => $u->id, 'scheduled_for' => '2026-10-05', 'status' => 'completed', 'client_uuid' => (string) Str::uuid()]);
    $se = SessionExercise::create(['workout_session_id' => $session->id, 'exercise_id' => $item->exercise_id, 'plan_day_exercise_id' => $item->id, 'position' => 1, 'target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 10]);

    $this->actingAs($a)->delete("/admin/plan/items/{$item->id}")->assertSessionHasNoErrors();

    expect($se->fresh()->plan_day_exercise_id)->toBeNull()->and($se->fresh()->exercise_id)->toBe($item->exercise_id);
});

// ---------------------------------------------------------------------------------------------
// Exercises
// ---------------------------------------------------------------------------------------------

function exercisePayload(array $over = []): array
{
    return array_merge([
        'name' => 'Zottman Curl', 'slug' => '', 'kind' => 'alternative', 'muscle_group' => 'biceps', 'mechanic' => 'isolation', 'difficulty' => 'intermediate',
        'equipment' => ['dumbbell'], 'secondary_groups' => ['forearms', 'biceps'], 'is_timed' => 0, 'per_side' => 0,
        'rest_min_seconds' => 60, 'rest_max_seconds' => 90, 'default_rest_seconds' => 75, 'description' => 'Curl up, rotate, lower.',
        'form_cues' => "Keep elbows still\n\nRotate at the top", 'common_mistakes' => 'Swinging', 'is_active' => 1,
    ], $over);
}

test('an exercise can be created with a generated slug, split cues and tidy secondary muscles', function () {
    $this->actingAs(admin())->post('/admin/exercises', exercisePayload())->assertSessionHasNoErrors();

    $e = Exercise::where('slug', 'zottman-curl')->firstOrFail();
    expect($e->form_cues)->toBe(['Keep elbows still', 'Rotate at the top'])
        ->and($e->common_mistakes)->toBe(['Swinging'])
        ->and($e->secondary_groups)->toBe(['forearms'])        // the primary muscle is not repeated
        ->and($e->equipment)->toBe(['dumbbell']);
});

test('exercise input is validated', function (array $bad, string $field) {
    $this->actingAs(admin())->post('/admin/exercises', exercisePayload($bad))->assertSessionHasErrors($field);
    expect(Exercise::where('name', 'Zottman Curl')->exists())->toBeFalse();
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad kind' => [['kind' => 'magic'], 'kind'],
    'bad muscle' => [['muscle_group' => 'wings'], 'muscle_group'],
    'rest max below min' => [['rest_min_seconds' => 120, 'rest_max_seconds' => 60], 'rest_max_seconds'],
    'duplicate slug' => [['slug' => 'push-up'], 'slug'],
    'bad demo key' => [['demo_key' => 'Has Spaces!'], 'demo_key'],
]);

test('editing an exercise saves changes and keeps the slug unique against others only', function () {
    $a = admin();
    $e = Exercise::where('slug', 'barbell-curl')->firstOrFail();

    $this->actingAs($a)->patch("/admin/exercises/{$e->slug}", exercisePayload(['name' => 'Barbell Curl (strict)', 'slug' => 'barbell-curl']))->assertSessionHasNoErrors();
    expect($e->fresh()->name)->toBe('Barbell Curl (strict)');
    $this->actingAs($a)->patch("/admin/exercises/{$e->slug}", exercisePayload(['slug' => 'push-up']))->assertSessionHasErrors('slug');
});

test('removing an exercise in use hides it, an unused one is deleted', function () {
    $a = admin();
    $used = PlanDayExercise::first()->exercise;
    $unused = Exercise::create(['slug' => 'temp-move', 'name' => 'Temp move', 'kind' => 'alternative', 'muscle_group' => 'core', 'mechanic' => 'isolation']);

    $this->actingAs($a)->delete("/admin/exercises/{$used->slug}")->assertRedirect();
    expect($used->fresh()->is_active)->toBeFalse();

    $this->actingAs($a)->delete("/admin/exercises/{$unused->slug}")->assertRedirect();
    expect(Exercise::where('slug', 'temp-move')->exists())->toBeFalse();
});

test('an exercise with posture history is hidden rather than deleted', function () {
    $e = Exercise::create(['slug' => 'temp-posture', 'name' => 'Temp posture', 'kind' => 'alternative', 'muscle_group' => 'core', 'mechanic' => 'isolation']);
    PostureSession::factory()->create(['user_id' => member()->id, 'exercise_id' => $e->id]);

    $this->actingAs(admin())->delete("/admin/exercises/{$e->slug}")->assertRedirect();

    expect(Exercise::where('slug', 'temp-posture')->exists())->toBeTrue()->and($e->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Posture rules
// ---------------------------------------------------------------------------------------------

function ruleForm(Exercise $exercise, ?callable $edit = null): array
{
    $rule = $exercise->activePostureRule();
    $config = $rule->config;
    if ($edit) {
        $config = $edit($config);
    }

    return ['tier' => $rule->tier->value, 'camera_view' => $rule->camera_view->value, 'config_json' => json_encode($config), 'guidance_json' => json_encode($rule->guidance)];
}

test('saving a valid rule creates the next version and retires the old one', function () {
    $a = admin();
    $e = Exercise::where('slug', 'barbell-curl')->with('postureRules')->firstOrFail();
    $old = $e->activePostureRule();

    $this->actingAs($a)->put("/admin/posture/{$e->slug}", ruleForm($e, function ($c) {
        $c['rep']['tolerance'] = 22;

        return $c;
    }))->assertSessionHasNoErrors();

    $rules = PostureRule::where('exercise_id', $e->id)->orderBy('version')->get();
    expect($rules)->toHaveCount(2)
        ->and($rules[0]->is_active)->toBeFalse()->and($rules[0]->config['rep']['tolerance'])->toBe($old->config['rep']['tolerance'])
        ->and($rules[1]->version)->toBe($old->version + 1)->and($rules[1]->is_active)->toBeTrue()->and($rules[1]->updated_by)->toBe($a->id)
        ->and($rules[1]->config['rep']['tolerance'])->toBe(22);
    expect($e->fresh('postureRules')->activePostureRule()->version)->toBe($old->version + 1);
});

test('an invalid rule is rejected with the validators messages and nothing is saved', function () {
    $e = Exercise::where('slug', 'barbell-curl')->firstOrFail();
    $form = ruleForm($e, function ($c) {
        $c['schema'] = 7;

        return $c;
    });

    $res = $this->actingAs(admin())->put("/admin/posture/{$e->slug}", $form);

    $res->assertSessionHasErrors('config_json');
    expect(session('errors')->get('config_json')[0])->toContain('schema must be 1')->and(PostureRule::where('exercise_id', $e->id)->count())->toBe(1);

    $this->actingAs(admin())->put("/admin/posture/{$e->slug}", ['tier' => 'full', 'camera_view' => 'side', 'config_json' => '{not json'])->assertSessionHasErrors('config_json');
    $this->actingAs(admin())->put("/admin/posture/{$e->slug}", ['tier' => 'full', 'camera_view' => 'side', 'config_json' => '[1,2]'])->assertSessionHasErrors('config_json');
});

test('the check endpoint reports validity and errors', function () {
    $a = admin();
    $e = Exercise::where('slug', 'barbell-curl')->firstOrFail();

    $ok = $this->actingAs($a)->postJson('/admin/posture/validate', ['config_json' => ruleForm($e)['config_json']])->assertOk();
    expect($ok->json('ok'))->toBeTrue()->and($ok->json('errors'))->toBe([]);

    $bad = $this->actingAs($a)->postJson('/admin/posture/validate', ['config_json' => '{"schema": 3}'])->assertOk();
    expect($bad->json('ok'))->toBeFalse()->and($bad->json('errors'))->not->toBeEmpty();
    expect($this->actingAs($a)->postJson('/admin/posture/validate', ['config_json' => 'nope'])->json('ok'))->toBeFalse();
});

test('a previous version can be restored as a new version, and reseeding leaves edited exercises alone', function () {
    $a = admin();
    $e = Exercise::where('slug', 'barbell-curl')->with('postureRules')->firstOrFail();
    $original = $e->activePostureRule();
    $this->actingAs($a)->put("/admin/posture/{$e->slug}", ruleForm($e, function ($c) {
        $c['rep']['tolerance'] = 30;

        return $c;
    }));

    $this->actingAs($a)->post("/admin/posture/{$e->slug}/restore/{$original->id}")->assertSessionHasNoErrors();

    $live = $e->fresh('postureRules')->activePostureRule();
    expect($live->version)->toBe(3)->and($live->config['rep']['tolerance'])->toBe($original->config['rep']['tolerance']);

    $this->seed(PostureRuleSeeder::class);
    expect($e->fresh('postureRules')->activePostureRule()->version)->toBe(3)->and(PostureRule::where('exercise_id', $e->id)->where('is_active', true)->count())->toBe(1);
});

test('a rule version cannot be restored through the wrong exercise', function () {
    $a = admin();
    $curl = Exercise::where('slug', 'barbell-curl')->firstOrFail();
    $squat = Exercise::whereHas('postureRules')->where('id', '!=', $curl->id)->firstOrFail();

    $this->actingAs($a)->post("/admin/posture/{$curl->slug}/restore/{$squat->postureRules()->first()->id}")->assertNotFound();
});

test('edited rules are served to the lab page immediately', function () {
    $a = admin();
    $e = Exercise::where('slug', 'barbell-curl')->with('postureRules')->firstOrFail();
    $this->actingAs($a)->put("/admin/posture/{$e->slug}", ruleForm($e, function ($c) {
        $c['rep']['tolerance'] = 21;

        return $c;
    }));

    $page = $this->actingAs($a)->get("/posture/{$e->slug}")->assertOk();

    expect($page->getContent())->toContain('"tolerance":21');
});

// ---------------------------------------------------------------------------------------------
// Foods
// ---------------------------------------------------------------------------------------------

function foodPayload(array $over = []): array
{
    return array_merge([
        'name' => 'Test Paneer Tikka', 'brand' => null, 'category' => 'dairy', 'diet_type' => 'vegetarian',
        'kcal_100g' => 265, 'protein_100g' => 18, 'carbs_100g' => 6, 'fat_100g' => 19, 'fibre_100g' => 1, 'is_verified' => 1,
        'servings' => [['label' => '1 piece (30 g)', 'grams' => 30], ['label' => '100 g', 'grams' => 100]],
    ], $over);
}

test('a food can be added with servings, searched in the food log and edited', function () {
    $a = admin();
    $this->actingAs($a)->post('/admin/foods', foodPayload())->assertSessionHasNoErrors();

    $food = Food::where('name', 'Test Paneer Tikka')->firstOrFail();
    expect($food->user_id)->toBeNull()->and($food->is_public)->toBeTrue()->and($food->name_search)->toBe('test paneer tikka')
        ->and($food->servings)->toHaveCount(2)->and($food->servings->first()->is_default)->toBeTrue();

    $this->actingAs($a)->getJson('/api/foods/search?q=paneer+tikka')->assertOk()->assertJsonPath('foods.0.name', 'Test Paneer Tikka');

    $this->actingAs($a)->patch("/admin/foods/{$food->id}", foodPayload(['name' => 'Test Paneer Tikka Dry', 'servings' => [['label' => '1 cup', 'grams' => 150]]]))->assertSessionHasNoErrors();
    expect($food->fresh()->name)->toBe('Test Paneer Tikka Dry')->and($food->fresh()->servings()->count())->toBe(1);
});

test('food input is validated, including macros that cannot fit in 100 g', function (array $bad, string $field) {
    $this->actingAs(admin())->post('/admin/foods', foodPayload($bad))->assertSessionHasErrors($field);
    expect(Food::where('name', 'Test Paneer Tikka')->exists())->toBeFalse();
})->with([
    'no name' => [['name' => ''], 'name'],
    'negative kcal' => [['kcal_100g' => -1], 'kcal_100g'],
    'protein over 100' => [['protein_100g' => 120], 'protein_100g'],
    'macros add up over 100 g' => [['protein_100g' => 60, 'carbs_100g' => 50], 'protein_100g'],
    'bad diet' => [['diet_type' => 'carnivore'], 'diet_type'],
    'no servings' => [['servings' => []], 'servings'],
    'serving without grams' => [['servings' => [['label' => 'cup', 'grams' => '']]], 'servings.0.grams'],
]);

test('users private custom foods are not editable or deletable from the admin panel', function () {
    $a = admin();
    $u = member();
    $custom = Food::create(['user_id' => $u->id, 'name' => 'My shake', 'name_search' => 'my shake', 'category' => 'custom', 'diet_type' => 'vegan', 'kcal_100g' => 100, 'protein_100g' => 10, 'carbs_100g' => 10, 'fat_100g' => 1, 'is_public' => false]);

    $this->actingAs($a)->get("/admin/foods/{$custom->id}/edit")->assertNotFound();
    $this->actingAs($a)->delete("/admin/foods/{$custom->id}")->assertNotFound();
    $this->actingAs($a)->get('/admin/foods?q=shake')->assertOk()->assertDontSee('My shake');
    expect(Food::whereKey($custom->id)->exists())->toBeTrue();
});

test('deleting a food keeps logged meals readable', function () {
    $a = admin();
    $u = member();
    $food = Food::whereNull('user_id')->first();
    $log = FoodLog::create(['user_id' => $u->id, 'food_id' => $food->id, 'logged_on' => '2026-10-05', 'meal' => 'lunch', 'name_snapshot' => $food->name, 'quantity' => 1, 'unit' => 'serving', 'grams' => 100, 'kcal' => 100, 'protein_g' => 10, 'carbs_g' => 10, 'fat_g' => 1, 'fibre_g' => 0]);

    $this->actingAs($a)->delete("/admin/foods/{$food->id}")->assertRedirect('/admin/foods');

    expect($log->fresh()->food_id)->toBeNull()->and($log->fresh()->name_snapshot)->toBe($food->name);
});

test('every admin form and listing page renders for an admin', function () {
    $a = admin();
    $u = member();
    $paths = [
        '/admin', '/admin/users', "/admin/users/{$u->id}", '/admin/plan', '/admin/exercises', '/admin/exercises/create', '/admin/exercises/barbell-curl/edit',
        '/admin/posture', '/admin/posture/barbell-curl', '/admin/foods', '/admin/foods/create', '/admin/demos',
        '/admin/plan/days/'.PlanDay::first()->id, '/admin/foods/'.Food::whereNull('user_id')->first()->id.'/edit',
    ];

    foreach ($paths as $path) {
        $this->actingAs($a)->get($path)->assertOk();
    }
});

test('the posture editor shows the live version, every saved version and the next version number', function () {
    $a = admin();
    $e = Exercise::where('slug', 'barbell-curl')->with('postureRules')->firstOrFail();
    $this->actingAs($a)->put("/admin/posture/{$e->slug}", ruleForm($e));

    $this->actingAs($a)->get("/admin/posture/{$e->slug}")->assertOk()->assertSee('Save as version 3')->assertSee('Restore as new version')->assertSee('Live');
});
