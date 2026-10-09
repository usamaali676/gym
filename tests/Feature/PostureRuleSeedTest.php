<?php

use App\Enums\PostureTier;
use App\Models\Exercise;
use App\Models\PostureRule;
use App\Models\User;
use Database\Seeders\PostureRuleSeeder;

beforeEach(fn () => seedPosture());

test('every plan exercise and every warm-up helper gets an active posture rule', function () {
    expect(PostureRule::count())->toBe(35)
        ->and(PostureRule::where('tier', PostureTier::Full)->count())->toBe(18)
        ->and(PostureRule::where('tier', PostureTier::Simplified)->count())->toBe(13)
        ->and(PostureRule::where('tier', PostureTier::Generic)->count())->toBe(4);

    $plan = json_decode(file_get_contents(database_path('seeders/data/plan.json')), true);
    foreach ($plan['exercises'] as $e) {
        $rule = Exercise::where('slug', $e['slug'])->firstOrFail()->postureRules()->where('is_active', true)->first();
        expect($rule)->not->toBeNull("{$e['slug']} has no rule")
            ->and($rule->tier->value)->toBe($e['posture_tier'], $e['slug']);
    }
});

test('the plan lists exactly the machine and cable lifts as simplified, including the cable overhead tricep extension', function () {
    $simplified = PostureRule::where('tier', PostureTier::Simplified)->with('exercise')->get()->pluck('exercise.slug')->all();

    expect($simplified)->toContain('cable-overhead-tricep-extension', 'lat-pulldown', 'leg-press', 'butterfly-machine', 'face-pull')
        ->and($simplified)->not->toContain('barbell-squat', 'flat-barbell-bench-press', 'plank');
});

test('seeding twice does not duplicate rules', function () {
    $this->seed(PostureRuleSeeder::class);
    $this->seed(PostureRuleSeeder::class);

    expect(PostureRule::count())->toBe(35);
});

test('a rule an admin has edited is not overwritten by re-seeding', function () {
    $admin = User::factory()->create();
    $rule = Exercise::where('slug', 'barbell-curl')->firstOrFail()->postureRules()->firstOrFail();
    $config = $rule->config;
    $config['rep']['tolerance'] = 20;
    $rule->update(['config' => $config, 'updated_by' => $admin->id]);

    $this->seed(PostureRuleSeeder::class);

    expect($rule->fresh()->config['rep']['tolerance'])->toBe(20);
});

test('the seeded rules carry configs and guidance the lab page needs', function () {
    foreach (PostureRule::all() as $rule) {
        expect($rule->config['schema'])->toBe(1)
            ->and($rule->guidance['camera']['steps'])->not->toBeEmpty()
            ->and($rule->guidance['accuracy'])->not->toBeEmpty()
            ->and($rule->is_active)->toBeTrue();
    }
});
