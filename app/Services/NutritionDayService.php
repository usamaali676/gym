<?php

namespace App\Services;

use App\Models\DietPlan;
use App\Models\FoodLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Everything the food page needs to know about one day: totals, targets, what is left and what would fit. */
class NutritionDayService
{
    public function __construct(
        private readonly NutritionTotalsService $nutrition,
        private readonly ProteinGapService $gaps,
        private readonly PlanService $plans,
    ) {}

    /**
     * @return array{date:string, is_training_day:bool, totals:array<string,float>, targets:?array<string,int>, remaining:?array<string,float>, suggestions:list<array<string,mixed>>}
     */
    public function state(User $user, CarbonInterface $date, ?DietPlan $plan = null): array
    {
        $plan ??= $this->nutrition->activePlan($user);
        $totals = $this->nutrition->totalsFor($user, $date);
        $isTraining = $this->plans->isTrainingDay($user, $date);
        $targets = $plan?->targetsFor($isTraining);

        $remaining = $targets ? [
            'calories' => round($targets['calories'] - $totals['calories'], 1),
            'protein_g' => round($targets['protein_g'] - $totals['protein_g'], 1),
            'carbs_g' => round($targets['carbs_g'] - $totals['carbs_g'], 1),
            'fat_g' => round($targets['fat_g'] - $totals['fat_g'], 1),
            'fibre_g' => round($targets['fibre_g'] - $totals['fibre_g'], 1),
        ] : null;

        $suggestions = ($plan && $remaining)
            ? $this->gaps->suggest($user, $remaining['protein_g'], $remaining['calories'], $plan->diet_preference)
            : [];

        return [
            'date' => $date->toDateString(),
            'is_training_day' => $isTraining,
            'totals' => $totals,
            'targets' => $targets,
            'remaining' => $remaining,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Calories and protein per day for the last `$days` days next to that day's targets (for the chart).
     *
     * @return list<array{date:string, calories:float, protein_g:float, target_calories:?int, target_protein_g:?int, training:bool}>
     */
    public function history(User $user, int $days = 14, ?DietPlan $plan = null): array
    {
        $plan ??= $this->nutrition->activePlan($user);
        $end = $user->localToday();
        $start = $end->subDays($days - 1);

        $rows = FoodLog::query()
            ->where('user_id', $user->id)
            // whereDate: logged_on is stored with a time part, so comparing the raw column to 'Y-m-d' would drop the last day
            ->whereDate('logged_on', '>=', $start->toDateString())
            ->whereDate('logged_on', '<=', $end->toDateString())
            ->selectRaw('logged_on, SUM(kcal) as kcal, SUM(protein_g) as protein')
            ->groupBy('logged_on')
            ->get()
            ->mapWithKeys(fn ($r) => [CarbonImmutable::parse($r->logged_on)->toDateString() => $r]);

        $out = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $training = $this->plans->isTrainingDay($user, $d);
            $targets = $plan?->targetsFor($training);
            $out[] = [
                'date' => $key,
                'calories' => round((float) ($rows[$key]->kcal ?? 0), 1),
                'protein_g' => round((float) ($rows[$key]->protein ?? 0), 1),
                'target_calories' => $targets['calories'] ?? null,
                'target_protein_g' => $targets['protein_g'] ?? null,
                'training' => $training,
            ];
        }

        return $out;
    }
}
