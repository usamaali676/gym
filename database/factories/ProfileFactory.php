<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Profile> */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::default()?->id,
            'age' => 27,
            'sex' => 'male',
            'height_cm' => 178,
            'weight_kg' => 78,
            'activity_level' => 'moderate',
            'fitness_level' => 'intermediate',
            'goal' => 'recomposition',
            'diet_preference' => 'nonveg',
            'equipment' => array_keys(config('ironpulse.equipment')),
            'days_per_week' => 5,
            'training_days' => [1, 2, 3, 4, 5],
            'timezone' => 'UTC',
            'plan_started_on' => '2026-10-05',
        ];
    }
}
