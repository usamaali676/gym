<?php

namespace Database\Seeders;

use App\Enums\DietPreference;
use App\Models\Food;
use App\Models\MealTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds sample meals (database/seeders/data/meal-templates.json). Each item names a food from the food database;
 * the macros of a template are computed from those foods, never typed by hand, and a template may not contain a
 * food its diet type forbids (a vegan meal cannot have paneer in it).
 */
class MealTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/meal-templates.json')), true, 512, JSON_THROW_ON_ERROR);
        $foods = Food::query()->whereNull('user_id')->get()->keyBy('name');

        DB::transaction(function () use ($data, $foods) {
            foreach ($data['templates'] as $t) {
                $diet = DietPreference::from($t['diet']);
                $totals = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0, 'fibre' => 0.0];
                $items = [];

                foreach ($t['items'] as [$name, $grams]) {
                    $food = $foods[$name] ?? throw new RuntimeException("Meal \"{$t['name']}\": unknown food \"{$name}\".");
                    if (DietPreference::from($food->diet_type)->rank() > $diet->rank()) {
                        throw new RuntimeException("Meal \"{$t['name']}\" is {$diet->value} but contains {$name} ({$food->diet_type}).");
                    }
                    $f = $grams / 100;
                    $totals['kcal'] += $food->kcal_100g * $f;
                    $totals['protein'] += $food->protein_100g * $f;
                    $totals['carbs'] += $food->carbs_100g * $f;
                    $totals['fat'] += $food->fat_100g * $f;
                    $totals['fibre'] += $food->fibre_100g * $f;
                    $items[] = ['food_id' => $food->id, 'name' => $food->name, 'grams' => $grams];
                }

                MealTemplate::updateOrCreate(
                    ['name' => $t['name'], 'diet_preference' => $diet->value, 'slot' => $t['slot']],
                    [
                        'goal' => null,
                        'items' => $items,
                        'kcal' => (int) round($totals['kcal']),
                        'protein_g' => (int) round($totals['protein']),
                        'carbs_g' => (int) round($totals['carbs']),
                        'fat_g' => (int) round($totals['fat']),
                        'fibre_g' => (int) round($totals['fibre']),
                        'tags' => $t['tags'] ?? [],
                    ],
                );
            }
        });
    }
}
