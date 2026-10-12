<?php

namespace App\Services;

use App\Enums\Meal;
use App\Models\DietPlan;
use App\Models\Food;
use App\Models\MealTemplate;
use Illuminate\Support\Collection;

/**
 * Builds a sample day of eating from the meal templates: for each meal it picks the template whose size and
 * protein are closest to that meal's share of the day's targets, scales the portions to fit, and offers a few
 * alternatives to swap in. The macros shown are recomputed from the scaled foods, so they are what you would actually eat.
 */
class MealPlanService
{
    /** Share of the day's calories and protein each meal should carry. */
    public const SLOT_SHARE = ['breakfast' => 0.25, 'lunch' => 0.30, 'snack' => 0.15, 'dinner' => 0.30];

    private const MIN_SCALE = 0.6;

    private const MAX_SCALE = 1.8;

    /**
     * @return list<array{slot:string,label:string,target_kcal:int,target_protein_g:int,meal:array<string,mixed>,alternatives:list<array<string,mixed>>}>
     */
    public function suggestDay(DietPlan $plan, bool $isTrainingDay, int $alternatives = 3): array
    {
        $targets = $plan->targetsFor($isTrainingDay);

        $templates = MealTemplate::query()
            ->whereIn('diet_preference', $plan->diet_preference->allowedFoodTypes())
            ->orderBy('id')
            ->get();
        $foods = Food::query()->whereIn('id', $templates->flatMap(fn (MealTemplate $t) => array_column($t->items, 'food_id'))->unique())->get()->keyBy('id');

        $out = [];
        foreach (Meal::cases() as $meal) {
            $share = self::SLOT_SHARE[$meal->value];
            $slotKcal = $targets['calories'] * $share;
            $slotProtein = $targets['protein_g'] * $share;

            $ranked = $templates
                ->filter(fn (MealTemplate $t) => $t->slot === $meal)
                ->sortBy(fn (MealTemplate $t) => [$this->distance($t, $slotKcal, $slotProtein), $t->id])
                ->values();

            if ($ranked->isEmpty()) {
                continue;
            }

            $choices = $ranked->take(1 + $alternatives)->map(fn (MealTemplate $t) => $this->scale($t, $this->factor($t, $slotKcal), $foods));

            $out[] = [
                'slot' => $meal->value,
                'label' => $meal->label(),
                'target_kcal' => (int) round($slotKcal),
                'target_protein_g' => (int) round($slotProtein),
                'meal' => $choices->first(),
                'alternatives' => $choices->slice(1)->values()->all(),
            ];
        }

        return $out;
    }

    /** Scale a template by `factor` and recompute its macros from the foods. @return array<string, mixed> */
    public function scale(MealTemplate $template, float $factor, Collection $foods): array
    {
        $items = [];
        $sum = ['kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0, 'fibre_g' => 0.0];

        foreach ($template->items as $item) {
            $food = $foods->get($item['food_id']);
            if (! $food) {
                continue;
            }
            $grams = $this->portion($item['grams'] * $factor, (float) $item['grams']);
            $f = $grams / 100;
            $sum['kcal'] += $food->kcal_100g * $f;
            $sum['protein_g'] += $food->protein_100g * $f;
            $sum['carbs_g'] += $food->carbs_100g * $f;
            $sum['fat_g'] += $food->fat_100g * $f;
            $sum['fibre_g'] += $food->fibre_100g * $f;
            $items[] = ['food_id' => $food->id, 'name' => $food->name, 'grams' => $grams];
        }

        return [
            'template_id' => $template->id,
            'name' => $template->name,
            'tags' => $template->tags ?? [],
            'scale' => round($factor, 2),
            'items' => $items,
            'kcal' => (int) round($sum['kcal']),
            'protein_g' => (int) round($sum['protein_g']),
            'carbs_g' => (int) round($sum['carbs_g']),
            'fat_g' => (int) round($sum['fat_g']),
            'fibre_g' => (int) round($sum['fibre_g']),
        ];
    }

    /** How far a template is from a meal's share of the day: size mismatch plus protein mismatch (lower is better). */
    private function distance(MealTemplate $t, float $slotKcal, float $slotProtein): float
    {
        $factor = $this->factor($t, $slotKcal);
        $sizeGap = abs(log($factor));
        $proteinGap = abs($t->protein_g * $factor - $slotProtein) / max(1.0, $slotProtein);

        return $sizeGap + 0.6 * $proteinGap;
    }

    private function factor(MealTemplate $t, float $slotKcal): float
    {
        return max(self::MIN_SCALE, min(self::MAX_SCALE, $slotKcal / max(1, $t->kcal)));
    }

    /** Round a portion to something you can measure: 1 g for tiny amounts (oil, seeds), 5 g otherwise. */
    private function portion(float $grams, float $original): float
    {
        if ($original < 20) {
            return max(1.0, round($grams));
        }

        return max(5.0, round($grams / 5) * 5);
    }
}
