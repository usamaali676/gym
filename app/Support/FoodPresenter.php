<?php

namespace App\Support;

use App\Models\Food;
use App\Models\FoodLog;

/** Shapes foods and log entries for the JSON the food page works with. */
final class FoodPresenter
{
    /** @return array<string, mixed> */
    public static function food(Food $food, ?int $userId = null): array
    {
        return [
            'id' => $food->id,
            'name' => $food->name,
            'brand' => $food->brand,
            'category' => $food->category,
            'diet_type' => $food->diet_type,
            'kcal_100g' => $food->kcal_100g,
            'protein_100g' => $food->protein_100g,
            'carbs_100g' => $food->carbs_100g,
            'fat_100g' => $food->fat_100g,
            'fibre_100g' => $food->fibre_100g,
            'custom' => $food->user_id !== null,
            'mine' => $userId !== null && $food->user_id === $userId,
            'servings' => $food->servings->map(fn ($s) => ['id' => $s->id, 'label' => $s->label, 'grams' => $s->grams, 'is_default' => $s->is_default])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function log(FoodLog $log): array
    {
        return [
            'id' => $log->id,
            'food_id' => $log->food_id,
            'meal' => $log->meal->value,
            'name' => $log->name_snapshot,
            'quantity' => $log->quantity,
            'unit' => $log->unit,
            'serving_label' => $log->serving_label,
            'grams' => $log->grams,
            'kcal' => $log->kcal,
            'protein_g' => $log->protein_g,
            'carbs_g' => $log->carbs_g,
            'fat_g' => $log->fat_g,
            'fibre_g' => $log->fibre_g,
        ];
    }
}
