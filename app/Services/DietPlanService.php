<?php

namespace App\Services;

use App\Models\DietPlan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates and refreshes a user's diet plan from their profile via the DietCalculatorService.
 * The profile is the single source of truth for body data, goal and diet preference; the plan is a snapshot
 * of the targets computed from them (plus the calorie-cycling switch, which only lives on the plan).
 */
class DietPlanService
{
    public function __construct(
        private readonly DietCalculatorService $calculator,
        private readonly NutritionTotalsService $nutrition,
    ) {}

    /** The active plan, created on first use when the profile is complete. */
    public function ensureActive(User $user): ?DietPlan
    {
        $plan = $this->nutrition->activePlan($user);
        if ($plan) {
            return $plan;
        }

        return $user->profile && $this->canBuild($user->profile) ? $this->build($user) : null;
    }

    public function canBuild(Profile $profile): bool
    {
        return $profile->age && $profile->sex && $profile->height_cm && $profile->weight_kg && $profile->activity_level && $profile->goal && $profile->diet_preference;
    }

    /**
     * Compute targets from the profile and store them as the new active plan (the old one is kept as history).
     *
     * @param  bool|null  $cycling  null keeps the current setting
     */
    public function build(User $user, ?bool $cycling = null): DietPlan
    {
        $profile = $user->profile ?? abort(409, 'Finish your profile first.');
        $previous = $this->nutrition->activePlan($user);
        $cycling ??= (bool) $previous?->calorie_cycling;
        $trainingDays = max(0, min(7, (int) ($profile->days_per_week ?? count($profile->trainingDays()))));

        $result = $this->calculator->calculate([
            'sex' => $profile->sex,
            'age' => $profile->age,
            'height_cm' => $profile->height_cm,
            'weight_kg' => $profile->weight_kg,
            'activity_level' => $profile->activity_level,
            'goal' => $profile->goal,
            'training_days_per_week' => $trainingDays,
            'calorie_cycling' => $cycling,
        ]);

        return DB::transaction(function () use ($user, $profile, $result, $trainingDays) {
            DietPlan::where('user_id', $user->id)->where('is_active', true)->update(['is_active' => false]);

            return DietPlan::create([
                'user_id' => $user->id,
                'goal' => $profile->goal,
                'diet_preference' => $profile->diet_preference,
                'age' => $profile->age,
                'sex' => $profile->sex,
                'height_cm' => $profile->height_cm,
                'weight_kg' => $profile->weight_kg,
                'activity_level' => $profile->activity_level,
                'training_days_per_week' => $trainingDays,
                'calorie_cycling' => $result['calorie_cycling'],
                'bmr' => $result['bmr'],
                'tdee' => $result['tdee'],
                'calories' => $result['calories'],
                'protein_g' => $result['protein_g'],
                'carbs_g' => $result['carbs_g'],
                'fat_g' => $result['fat_g'],
                'fibre_g' => $result['fibre_g'],
                'water_ml' => $result['water_ml'],
                'rest_day_targets' => $result['rest_day_targets'],
                'protein_g_per_kg' => $result['protein_g_per_kg'],
                'formula_version' => $result['formula_version'],
                'is_active' => true,
            ]);
        });
    }

    /** Has the profile moved on since the targets were computed? */
    public function isStale(DietPlan $plan, Profile $profile): bool
    {
        return $plan->age !== (int) $profile->age
            || $plan->sex !== $profile->sex
            || abs($plan->height_cm - $profile->height_cm) > 0.05
            || abs($plan->weight_kg - $profile->weight_kg) > 0.05
            || $plan->activity_level !== $profile->activity_level
            || $plan->goal !== $profile->goal
            || $plan->diet_preference !== $profile->diet_preference
            || $plan->formula_version !== DietCalculatorService::FORMULA_VERSION
            || $plan->training_days_per_week !== (int) $profile->days_per_week;
    }

    /**
     * The numbers behind a plan, for the "how was this calculated" panel.
     *
     * @return array<string, mixed>
     */
    public function explain(DietPlan $plan): array
    {
        $adjustment = round(($plan->calories - $plan->tdee) / max(1, $plan->tdee) * 100, 1);

        return [
            'bmr' => $plan->bmr,
            'multiplier' => $plan->activity_level->multiplier(),
            'tdee' => $plan->tdee,
            'goal_pct' => $plan->calorie_cycling ? null : $adjustment,
            'protein_g_per_kg' => (float) $plan->protein_g_per_kg,
        ];
    }
}
