<?php

namespace App\Services;

use App\Models\DietPlan;
use App\Models\FoodLog;
use App\Models\User;
use Carbon\CarbonInterface;

class NutritionTotalsService
{
    public function __construct(private readonly PlanService $plans) {}

    /** @return array{calories:float,protein_g:float,carbs_g:float,fat_g:float,fibre_g:float} */
    public function totalsFor(User $user, CarbonInterface $date): array
    {
        $row = FoodLog::query()
            ->where('user_id', $user->id)
            ->whereDate('logged_on', $date->toDateString())
            ->selectRaw('COALESCE(SUM(kcal),0) as calories, COALESCE(SUM(protein_g),0) as protein_g, COALESCE(SUM(carbs_g),0) as carbs_g, COALESCE(SUM(fat_g),0) as fat_g, COALESCE(SUM(fibre_g),0) as fibre_g')
            ->first();

        return [
            'calories' => round((float) $row->calories, 1),
            'protein_g' => round((float) $row->protein_g, 1),
            'carbs_g' => round((float) $row->carbs_g, 1),
            'fat_g' => round((float) $row->fat_g, 1),
            'fibre_g' => round((float) $row->fibre_g, 1),
        ];
    }

    public function activePlan(User $user): ?DietPlan
    {
        return DietPlan::query()->where('user_id', $user->id)->where('is_active', true)->latest('id')->first();
    }

    /**
     * Targets for a given date (rest days differ when calorie cycling is on).
     *
     * @return array{calories:int,protein_g:int,carbs_g:int,fat_g:int,fibre_g:int}|null
     */
    public function targetsFor(User $user, CarbonInterface $date, ?DietPlan $plan = null): ?array
    {
        $plan ??= $this->activePlan($user);

        return $plan?->targetsFor($this->plans->isTrainingDay($user, $date));
    }
}
