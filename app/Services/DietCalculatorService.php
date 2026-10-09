<?php

namespace App\Services;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use InvalidArgumentException;

/**
 * Daily calorie and macro targets.
 *
 *   BMR   Mifflin-St Jeor: 10 x kg + 6.25 x cm - 5 x age + s   (s: +5 male, -161 female, -78 when unspecified)
 *   TDEE  BMR x activity multiplier (1.2 / 1.375 / 1.55 / 1.725 / 1.9)
 *   Goal  cutting -20% (at most 1000 kcal, never below BMR or a 1200/1500 kcal floor),
 *         bulking +10% (between +200 and +500 kcal), recomposition -5%, maintenance 0
 *   Protein  g per kg of body weight: cutting 2.3, recomposition 2.1, bulking 1.9, maintenance 1.8
 *            (bands 2.2-2.4 / 2.0-2.2 / 1.8-2.0 / 1.8), never more than 40% of calories
 *   Fat   25% of calories (28% maintenance), kept between 20% and 30% and above 0.6 g per kg
 *   Carbs the remainder, at least 100 g (fat, then protein, give way before carbs do)
 *   Fibre 14 g per 1000 kcal.  Water 35 ml per kg (+500 ml on training days).
 *
 * Calorie cycling is weekly-neutral: rest days eat 12% less and training days eat the difference, so the week
 * adds up to exactly 7 x the base target.
 *
 * These are population formulas, not medical advice: the result is a starting point to adjust from
 * what the scale and the mirror do over 2-3 weeks.
 */
class DietCalculatorService
{
    public const FORMULA_VERSION = 'msj-1';

    private const SEX_FLOOR = ['male' => 1500, 'female' => 1200, 'unspecified' => 1200];

    private const PROTEIN_G_PER_KG = ['cutting' => 2.3, 'recomposition' => 2.1, 'bulking' => 1.9, 'maintenance' => 1.8];

    private const PROTEIN_BAND_MIN = ['cutting' => 2.2, 'recomposition' => 2.0, 'bulking' => 1.8, 'maintenance' => 1.6];

    private const FAT_PCT = ['cutting' => 0.25, 'recomposition' => 0.25, 'bulking' => 0.25, 'maintenance' => 0.28];

    private const REST_DAY_REDUCTION = 0.12;

    private const MIN_CARBS_G = 100;

    public function __construct(
        private readonly float $waterMlPerKg = 35.0,
        private readonly int $trainingDayWaterBonusMl = 500,
    ) {}

    /**
     * @param  array{sex: Sex|string, age: int, height_cm: float|int, weight_kg: float|int, activity_level: ActivityLevel|string, goal: Goal|string, training_days_per_week?: int, calorie_cycling?: bool}  $in
     * @return array{
     *   bmr:int, tdee:int, goal_adjustment_pct:float, calories:int, protein_g:int, carbs_g:int, fat_g:int, fibre_g:int,
     *   water_ml:int, training_day_water_bonus_ml:int, protein_g_per_kg:float, calorie_cycling:bool,
     *   rest_day_targets:?array{calories:int,protein_g:int,carbs_g:int,fat_g:int,fibre_g:int},
     *   weekly_calories:int, notes:list<string>, formula_version:string
     * }
     */
    public function calculate(array $in): array
    {
        $sex = $in['sex'] instanceof Sex ? $in['sex'] : Sex::from($in['sex']);
        $goal = $in['goal'] instanceof Goal ? $in['goal'] : Goal::from($in['goal']);
        $activity = $in['activity_level'] instanceof ActivityLevel ? $in['activity_level'] : ActivityLevel::from($in['activity_level']);
        $age = (int) $in['age'];
        $height = (float) $in['height_cm'];
        $weight = (float) $in['weight_kg'];
        $trainingDays = max(0, min(7, (int) ($in['training_days_per_week'] ?? 5)));

        if ($age < 10 || $age > 100 || $height < 100 || $height > 250 || $weight < 30 || $weight > 300) {
            throw new InvalidArgumentException('Age, height or weight is outside the range these formulas are meant for.');
        }

        $notes = [];
        $bmrRaw = $this->bmr($sex, $age, $height, $weight);
        $tdeeRaw = $bmrRaw * $activity->multiplier();
        $bmr = (int) round($bmrRaw);
        $tdee = (int) round($tdeeRaw);

        // --- goal adjustment -------------------------------------------------------------------
        $target = match ($goal) {
            Goal::Cutting => $tdeeRaw - min(0.20 * $tdeeRaw, 1000),
            Goal::Recomposition => $tdeeRaw * 0.95,
            Goal::Bulking => $tdeeRaw + max(200, min(500, 0.10 * $tdeeRaw)),
            Goal::Maintenance => $tdeeRaw,
        };

        $floor = self::SEX_FLOOR[$sex->value];
        if ($goal === Goal::Cutting || $goal === Goal::Recomposition) {
            $minimum = max($floor, $bmr);
            if ($target < $minimum) {
                $target = $minimum;
                $notes[] = "Your deficit was reduced so calories stay at or above your BMR ({$bmr} kcal). Cut slowly rather than eating less.";
            }
        } elseif ($target < $floor) {
            $target = $floor;
            $notes[] = "Calories were raised to the {$floor} kcal minimum.";
        }
        $base = (int) round($target);
        $pct = round(($base - $tdee) / $tdee * 100, 1);

        // --- calorie cycling (weekly neutral) --------------------------------------------------
        $restDays = 7 - $trainingDays;
        $wantsCycling = (bool) ($in['calorie_cycling'] ?? false);
        $cycling = $wantsCycling && $trainingDays >= 1 && $restDays >= 1;
        if ($wantsCycling && ! $cycling) {
            $notes[] = 'Calorie cycling needs both training and rest days in the week, so it was left off.';
        }

        $trainingCalories = $base;
        $restCalories = $base;
        if ($cycling) {
            $restCalories = max($floor, (int) round($base * (1 - self::REST_DAY_REDUCTION)));
            $trainingCalories = (int) round((7 * $base - $restDays * $restCalories) / $trainingDays);
        }

        $training = $this->macros($trainingCalories, $weight, $goal, $notes);
        $rest = $cycling ? $this->macros($restCalories, $weight, $goal, $notes, quiet: true) : null;

        return [
            'bmr' => $bmr,
            'tdee' => $tdee,
            'goal_adjustment_pct' => $pct,
            'calories' => $trainingCalories,
            'protein_g' => $training['protein_g'],
            'carbs_g' => $training['carbs_g'],
            'fat_g' => $training['fat_g'],
            'fibre_g' => $training['fibre_g'],
            'water_ml' => $this->roundTo50($this->waterMlPerKg * $weight),
            'training_day_water_bonus_ml' => $this->trainingDayWaterBonusMl,
            'protein_g_per_kg' => $training['protein_g_per_kg'],
            'calorie_cycling' => $cycling,
            'rest_day_targets' => $rest ? ['calories' => $restCalories] + array_intersect_key($rest, array_flip(['protein_g', 'carbs_g', 'fat_g', 'fibre_g'])) : null,
            'weekly_calories' => $cycling ? $trainingDays * $trainingCalories + $restDays * $restCalories : 7 * $base,
            'notes' => array_values(array_unique($notes)),
            'formula_version' => self::FORMULA_VERSION,
        ];
    }

    /** Mifflin-St Jeor basal metabolic rate in kcal/day (unrounded). */
    public function bmr(Sex $sex, int $age, float $heightCm, float $weightKg): float
    {
        $constant = match ($sex) {
            Sex::Male => 5,
            Sex::Female => -161,
            Sex::Unspecified => -78, // midpoint of the two
        };

        return 10 * $weightKg + 6.25 * $heightCm - 5 * $age + $constant;
    }

    /**
     * Split a calorie target into macros.
     *
     * @param  list<string>  $notes  warnings are appended here
     * @return array{protein_g:int,carbs_g:int,fat_g:int,fibre_g:int,protein_g_per_kg:float}
     */
    public function macros(int $calories, float $weightKg, Goal $goal, array &$notes = [], bool $quiet = false): array
    {
        $perKg = self::PROTEIN_G_PER_KG[$goal->value];
        $protein = $perKg * $weightKg;

        // Protein is capped at 40% of calories (very heavy people at a low target would otherwise be told to eat more protein than energy).
        if ($protein * 4 > 0.40 * $calories) {
            $protein = 0.40 * $calories / 4;
            $perKg = $protein / $weightKg;
            if (! $quiet) {
                $notes[] = 'Protein was capped at 40% of your calories.';
            }
        }

        // Fat: a fixed share of calories, at least 0.6 g/kg, and no more than 30% of calories (unless 0.6 g/kg itself is more).
        $fatMin = 0.6 * $weightKg;
        $fatMax = max(0.30 * $calories / 9, $fatMin);
        $fat = min(max(self::FAT_PCT[$goal->value] * $calories / 9, $fatMin), $fatMax);

        $carbs = ($calories - 4 * $protein - 9 * $fat) / 4;

        if ($carbs < self::MIN_CARBS_G) {
            // 1) fat gives way down to 20% of calories (but not below 0.6 g/kg)
            $fat = max(0.20 * $calories / 9, $fatMin);
            $carbs = ($calories - 4 * $protein - 9 * $fat) / 4;
        }
        if ($carbs < self::MIN_CARBS_G) {
            // 2) then protein gives way down to the bottom of its band
            $protein = min($protein, self::PROTEIN_BAND_MIN[$goal->value] * $weightKg);
            $perKg = $protein / $weightKg;
            $carbs = ($calories - 4 * $protein - 9 * $fat) / 4;
        }
        if ($carbs < self::MIN_CARBS_G / 2 && ! $quiet) {
            $notes[] = 'This calorie target leaves very little room for carbohydrate at your body weight. Training will suffer; consider a gentler goal or talk to a dietitian.';
        }

        $p = (int) round($protein);
        $f = (int) round($fat);
        $c = max(0, (int) round(($calories - 4 * $p - 9 * $f) / 4));

        return [
            'protein_g' => $p,
            'carbs_g' => $c,
            'fat_g' => $f,
            'fibre_g' => (int) round(14 * $calories / 1000),
            'protein_g_per_kg' => round($perKg, 2),
        ];
    }

    /** Water goal for a day: base amount, plus a bonus on training days. */
    public function waterGoal(int $baseMl, bool $isTrainingDay): int
    {
        return $baseMl + ($isTrainingDay ? $this->trainingDayWaterBonusMl : 0);
    }

    private function roundTo50(float $ml): int
    {
        return (int) (round($ml / 50) * 50);
    }
}
