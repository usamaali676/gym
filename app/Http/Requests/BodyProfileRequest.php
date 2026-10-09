<?php

namespace App\Http\Requests;

use App\Enums\ActivityLevel;
use App\Enums\DietPreference;
use App\Enums\FitnessLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\UnitSystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared by the onboarding wizard and the "edit body profile" form. */
class BodyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'public_name' => ['nullable', 'string', 'max:60'],
            'age' => ['required', 'integer', 'between:14,90'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'height_cm' => ['required', 'numeric', 'between:100,250'],
            'weight_kg' => ['required', 'numeric', 'between:30,300'],
            'activity_level' => ['required', Rule::enum(ActivityLevel::class)],
            'fitness_level' => ['required', Rule::enum(FitnessLevel::class)],
            'goal' => ['required', Rule::enum(Goal::class)],
            'diet_preference' => ['required', Rule::enum(DietPreference::class)],
            'equipment' => ['required', 'array', 'min:1'],
            'equipment.*' => ['string', Rule::in(array_keys(config('ironpulse.equipment')))],
            'training_days' => ['required', 'array', 'min:1', 'max:7'],
            'training_days.*' => ['integer', 'between:1,7'],
            'unit_system' => ['nullable', Rule::enum(UnitSystem::class)],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    public function attributes(): array
    {
        return [
            'height_cm' => 'height',
            'weight_kg' => 'weight',
            'training_days' => 'training days',
        ];
    }
}
