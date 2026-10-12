<?php

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Services\DietCalculatorService;

function diet(array $in = []): array
{
    return (new DietCalculatorService)->calculate($in + [
        'sex' => 'male', 'age' => 30, 'height_cm' => 180, 'weight_kg' => 80,
        'activity_level' => 'moderate', 'goal' => 'maintenance', 'training_days_per_week' => 5, 'calorie_cycling' => false,
    ]);
}

test('Mifflin-St Jeor BMR for each sex', function () {
    $s = new DietCalculatorService;

    expect($s->bmr(Sex::Male, 30, 180, 80))->toBe(1780.0)               // 800 + 1125 - 150 + 5
        ->and($s->bmr(Sex::Female, 25, 165, 60))->toBe(1345.25)         // 600 + 1031.25 - 125 - 161
        ->and($s->bmr(Sex::Unspecified, 30, 180, 80))->toBe(1780.0 - 83); // the midpoint constant (-78)
});

test('TDEE uses the standard activity multipliers', function (string $level, float $multiplier) {
    $r = diet(['activity_level' => $level]);

    expect($r['bmr'])->toBe(1780)->and($r['tdee'])->toBe((int) round(1780 * $multiplier));
})->with([
    'sedentary' => ['sedentary', 1.2],
    'light' => ['light', 1.375],
    'moderate' => ['moderate', 1.55],
    'active' => ['active', 1.725],
    'very active' => ['very_active', 1.9],
]);

test('maintenance eats at TDEE with protein 1.8 g/kg, 28% fat and the rest carbs', function () {
    $r = diet(['sex' => 'female', 'age' => 25, 'height_cm' => 165, 'weight_kg' => 60, 'activity_level' => 'sedentary']);

    expect($r['tdee'])->toBe(1614)->and($r['calories'])->toBe(1614)
        ->and($r['protein_g'])->toBe(108)->and($r['fat_g'])->toBe(50)->and($r['carbs_g'])->toBe(183)
        ->and($r['fibre_g'])->toBe(23)                    // 14 g per 1000 kcal
        ->and($r['protein_g_per_kg'])->toBe(1.8)
        ->and($r['goal_adjustment_pct'])->toBe(0.0)
        ->and($r['notes'])->toBe([]);
});

test('cutting is a 20% deficit with 2.3 g/kg protein', function () {
    $r = diet(['goal' => 'cutting']);

    expect($r['tdee'])->toBe(2759)->and($r['calories'])->toBe(2207)->and($r['goal_adjustment_pct'])->toBe(-20.0)
        ->and($r['protein_g'])->toBe(184)->and($r['fat_g'])->toBe(61)->and($r['carbs_g'])->toBe(231)
        ->and($r['fibre_g'])->toBe(31)
        ->and($r['water_ml'])->toBe(2800);
});

test('a cut never goes below BMR and says so', function () {
    $r = diet(['sex' => 'female', 'age' => 25, 'height_cm' => 165, 'weight_kg' => 60, 'activity_level' => 'sedentary', 'goal' => 'cutting']);

    expect($r['calories'])->toBe(1345)->and($r['calories'])->toBeGreaterThanOrEqual($r['bmr'])
        ->and(implode(' ', $r['notes']))->toContain('BMR');
});

test('bulking adds 10% of TDEE, between 200 and 500 kcal', function () {
    $normal = diet(['sex' => 'male', 'age' => 25, 'height_cm' => 175, 'weight_kg' => 70, 'activity_level' => 'active', 'goal' => 'bulking']);
    expect($normal['tdee'])->toBe(2887)->and($normal['calories'])->toBe(3176)->and($normal['protein_g_per_kg'])->toBe(1.9);

    $small = diet(['sex' => 'female', 'age' => 40, 'height_cm' => 155, 'weight_kg' => 45, 'activity_level' => 'sedentary', 'goal' => 'bulking']);
    expect($small['calories'] - $small['tdee'])->toBeBetween(199, 201);

    $big = diet(['weight_kg' => 200, 'height_cm' => 200, 'activity_level' => 'very_active', 'goal' => 'bulking']);
    expect($big['calories'] - $big['tdee'])->toBeBetween(499, 501);
});

test('recomposition is a small deficit with 2.1 g/kg protein', function () {
    $r = diet(['goal' => 'recomposition']);

    expect($r['goal_adjustment_pct'])->toBe(-5.0)->and($r['protein_g_per_kg'])->toBe(2.1)->and($r['protein_g'])->toBe(168);
});

test('protein bands: cutting 2.2-2.4, recomposition 2.0-2.2, bulking 1.8-2.0, maintenance 1.8', function (string $goal, float $low, float $high) {
    $r = diet(['goal' => $goal]);

    expect($r['protein_g_per_kg'])->toBeBetween($low, $high);
})->with([['cutting', 2.2, 2.4], ['recomposition', 2.0, 2.2], ['bulking', 1.8, 2.0], ['maintenance', 1.8, 1.8]]);

test('fat stays between 20% and 30% of calories', function (string $goal) {
    $r = diet(['goal' => $goal]);
    $share = $r['fat_g'] * 9 / $r['calories'];

    expect($share)->toBeGreaterThanOrEqual(0.20)->toBeLessThanOrEqual(0.30);
})->with(['cutting', 'bulking', 'maintenance', 'recomposition']);

test('fibre is 14 g per 1000 kcal', function () {
    expect(diet(['goal' => 'bulking'])['fibre_g'])->toBe((int) round(14 * diet(['goal' => 'bulking'])['calories'] / 1000));
});

test('water is 35 ml per kg rounded to 50 ml, with a training day bonus', function () {
    $r = diet(['weight_kg' => 73]);
    $s = new DietCalculatorService;

    expect($r['water_ml'])->toBe(2550)->and($r['training_day_water_bonus_ml'])->toBe(500)
        ->and($s->waterGoal($r['water_ml'], true))->toBe(3050)->and($s->waterGoal($r['water_ml'], false))->toBe(2550);
});

test('protein is capped at 40% of calories for heavy people on a low target', function () {
    $r = diet(['sex' => 'male', 'age' => 40, 'height_cm' => 170, 'weight_kg' => 150, 'activity_level' => 'sedentary', 'goal' => 'cutting']);

    expect($r['protein_g'] * 4 / $r['calories'])->toBeLessThanOrEqual(0.401)
        ->and(implode(' ', $r['notes']))->toContain('capped');
});

test('carbs do not fall below 100 g when calories allow it: fat, then protein, give way first', function () {
    $r = diet(['sex' => 'female', 'age' => 30, 'height_cm' => 160, 'weight_kg' => 90, 'activity_level' => 'sedentary', 'goal' => 'cutting']);

    expect($r['carbs_g'])->toBeGreaterThanOrEqual(100)->and($r['fat_g'] * 9 / $r['calories'])->toBeGreaterThanOrEqual(0.19);
});

test('calorie cycling is weekly neutral', function () {
    $r = diet(['goal' => 'cutting', 'calorie_cycling' => true]);

    expect($r['calorie_cycling'])->toBeTrue()
        ->and($r['rest_day_targets']['calories'])->toBe(1942)       // 12% under the 2207 base
        ->and($r['calories'])->toBe(2313)                           // training days take up the difference
        ->and(abs($r['weekly_calories'] - 7 * 2207))->toBeLessThanOrEqual(3)
        ->and($r['rest_day_targets']['protein_g'])->toBe($r['protein_g']);   // protein is the same every day
});

test('cycling works for any split and keeps the weekly total', function (int $trainingDays) {
    $r = diet(['goal' => 'maintenance', 'calorie_cycling' => true, 'training_days_per_week' => $trainingDays]);
    $base = diet(['goal' => 'maintenance'])['calories'];

    expect($r['calorie_cycling'])->toBeTrue()->and(abs($r['weekly_calories'] - 7 * $base))->toBeLessThanOrEqual($trainingDays)
        ->and($r['rest_day_targets']['calories'])->toBeLessThan($r['calories']);
})->with([1, 2, 3, 4, 5, 6]);

test('cycling is switched off with no rest days or no training days, and the note says why', function (int $trainingDays) {
    $r = diet(['calorie_cycling' => true, 'training_days_per_week' => $trainingDays]);

    expect($r['calorie_cycling'])->toBeFalse()->and($r['rest_day_targets'])->toBeNull()
        ->and(implode(' ', $r['notes']))->toContain('cycling');
})->with([0, 7]);

test('macros add back up to the calories for a wide range of people', function () {
    $s = new DietCalculatorService;
    $checked = 0;

    foreach (['male', 'female', 'unspecified'] as $sex) {
        foreach ([18, 35, 60] as $age) {
            foreach ([150, 175, 195] as $height) {
                foreach ([50, 75, 110] as $weight) {
                    foreach (Goal::cases() as $goal) {
                        foreach ([ActivityLevel::Sedentary, ActivityLevel::Active] as $activity) {
                            foreach ([false, true] as $cycling) {
                                $r = $s->calculate(['sex' => $sex, 'age' => $age, 'height_cm' => $height, 'weight_kg' => $weight, 'activity_level' => $activity, 'goal' => $goal, 'training_days_per_week' => 4, 'calorie_cycling' => $cycling]);
                                $days = [$r + ['_label' => 'training']];
                                if ($r['rest_day_targets']) {
                                    $days[] = $r['rest_day_targets'] + ['_label' => 'rest'];
                                }
                                foreach ($days as $d) {
                                    $kcal = 4 * $d['protein_g'] + 4 * $d['carbs_g'] + 9 * $d['fat_g'];
                                    // Carbs are clamped at 0 only in absurd inputs; otherwise the split must match the target.
                                    if ($d['carbs_g'] > 0) {
                                        expect(abs($kcal - $d['calories']))->toBeLessThanOrEqual(12, "{$sex} {$age} {$height} {$weight} {$goal->value} {$d['_label']}");
                                    }
                                    expect($d['calories'])->toBeGreaterThanOrEqual(1200)->and($d['protein_g'])->toBeGreaterThan(0);
                                }
                                $checked++;
                            }
                        }
                    }
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(1000);
});

test('inputs outside the range of the formulas are refused', function (array $override) {
    expect(fn () => diet($override))->toThrow(InvalidArgumentException::class);
})->with([
    'too young' => [['age' => 5]],
    'too old' => [['age' => 120]],
    'tiny height' => [['height_cm' => 60]],
    'huge weight' => [['weight_kg' => 600]],
    'tiny weight' => [['weight_kg' => 10]],
]);

test('enums and strings are interchangeable and the formula is versioned', function () {
    $a = diet();
    $b = (new DietCalculatorService)->calculate([
        'sex' => Sex::Male, 'age' => 30, 'height_cm' => 180, 'weight_kg' => 80,
        'activity_level' => ActivityLevel::Moderate, 'goal' => Goal::Maintenance,
    ]);

    expect($a['calories'])->toBe($b['calories'])->and($a['formula_version'])->toBe('msj-1');
});
