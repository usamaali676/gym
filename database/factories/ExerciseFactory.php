<?php

namespace Database\Factories;

use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Exercise> */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => ucwords($name),
            'kind' => 'alternative',
            'muscle_group' => 'chest',
            'mechanic' => 'isolation',
            'equipment' => ['dumbbell'],
            'difficulty' => 'beginner',
            'description' => fake()->sentence(),
            'form_cues' => ['Brace your core'],
            'common_mistakes' => ['Rushing'],
            'status' => 'published',
        ];
    }

    public function premium(string $entitlement = 'library.premium'): static
    {
        return $this->state(fn () => ['required_entitlement' => $entitlement]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'is_active' => false]);
    }
}
