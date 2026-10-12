<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FoodLog;

/** Food log entry as the apps receive it (values were computed and stored by the server when it was logged). */
final class LogPresenter
{
    /** @return array<string, mixed> */
    public static function log(FoodLog $log): array
    {
        return [
            'id' => $log->id,
            'food_id' => $log->food_id,
            'logged_on' => $log->logged_on->toDateString(),
            'meal' => $log->meal->value,
            'name' => $log->name_snapshot,
            'quantity' => (float) $log->quantity,
            'unit' => $log->unit,
            'serving_label' => $log->serving_label,
            'grams' => (float) $log->grams,
            'kcal' => (float) $log->kcal,
            'protein_g' => (float) $log->protein_g,
            'carbs_g' => (float) $log->carbs_g,
            'fat_g' => (float) $log->fat_g,
            'fibre_g' => (float) $log->fibre_g,
        ];
    }
}
