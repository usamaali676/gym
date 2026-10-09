<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            ExerciseLibrarySeeder::class,
            PostureRuleSeeder::class,
            FoodSeeder::class,
            MealTemplateSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
