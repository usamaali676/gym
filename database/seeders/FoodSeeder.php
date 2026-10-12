<?php

namespace Database\Seeders;

use App\Models\Food;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the shared food database (database/seeders/data/foods.json): per-100 g macros and common serving sizes.
 * Re-running updates the shared foods in place and never touches foods users created themselves.
 */
class FoodSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/foods.json')), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            foreach ($data['foods'] as $row) {
                $food = Food::updateOrCreate(
                    ['user_id' => null, 'name_search' => Food::normalise($row['name'])],
                    [
                        'name' => $row['name'],
                        'category' => $row['category'],
                        'diet_type' => $row['diet_type'],
                        'kcal_100g' => $row['kcal_100g'],
                        'protein_100g' => $row['protein_100g'],
                        'carbs_100g' => $row['carbs_100g'],
                        'fat_100g' => $row['fat_100g'],
                        'fibre_100g' => $row['fibre_100g'],
                        'is_public' => true,
                        'is_verified' => true,
                    ],
                );

                $food->servings()->delete();
                foreach ($row['servings'] as $serving) {
                    $food->servings()->create($serving);
                }
            }
        });
    }
}
