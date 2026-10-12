<?php

namespace App\Services;

use App\Enums\DietPreference;
use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "You are 38 g short on protein: here is what would fit." Suggests lean, protein-dense foods the user's diet allows,
 * sized so that one serving does not blow the calories left for the day.
 */
class ProteinGapService
{
    /** Below this gap (grams) the hint stays out of the way. */
    public const MIN_GAP_G = 15;

    /** Foods that are protein-dense but not a snack you should be nudged towards. */
    private const EXCLUDED_CATEGORIES = ['oils_fats', 'sweets_snacks', 'beverages', 'nuts_seeds'];

    /**
     * @return list<array{food_id:int, name:string, serving:string, grams:float, protein_g:float, kcal:float}>
     */
    public function suggest(User $user, float $remainingProtein, float $remainingKcal, DietPreference $diet, int $limit = 5): array
    {
        if ($remainingProtein < self::MIN_GAP_G) {
            return [];
        }

        /** @var Collection<int, Food> $candidates */
        $candidates = Food::query()
            ->visibleTo($user)
            ->with('servings')
            ->whereIn('diet_type', $diet->allowedFoodTypes())
            ->whereNotIn('category', self::EXCLUDED_CATEGORIES)
            ->where('protein_100g', '>=', 8)
            ->where('kcal_100g', '>', 0)
            ->get();

        return $candidates
            ->map(function (Food $food) {
                $serving = $food->servings->firstWhere('is_default', true) ?? $food->servings->first();
                $grams = $serving?->grams ?? 100.0;
                $f = $grams / 100;

                return [
                    'food' => $food,
                    'density' => $food->protein_100g / $food->kcal_100g * 100, // grams of protein per 100 kcal
                    'row' => [
                        'food_id' => $food->id,
                        'name' => $food->name,
                        'serving' => $serving?->label ?? '100 g',
                        'grams' => (float) $grams,
                        'protein_g' => round($food->protein_100g * $f, 1),
                        'kcal' => round($food->kcal_100g * $f, 1),
                    ],
                ];
            })
            ->filter(fn (array $c) => $c['density'] >= 8                                   // at least 8 g protein per 100 kcal
                && $c['row']['protein_g'] >= 6                                             // a serving that actually helps
                && ($remainingKcal <= 0 || $c['row']['kcal'] <= $remainingKcal + 60))      // fits what is left
            ->sortByDesc(fn (array $c) => $c['density'])
            ->take($limit)
            ->map(fn (array $c) => $c['row'])
            ->values()
            ->all();
    }
}
