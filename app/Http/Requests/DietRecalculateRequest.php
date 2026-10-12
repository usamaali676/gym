<?php

namespace App\Http\Requests;

use App\Enums\DietPreference;
use App\Enums\Goal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DietRecalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'goal' => ['required', Rule::in(Goal::values())],
            'diet_preference' => ['required', Rule::in(DietPreference::values())],
            'calorie_cycling' => ['nullable', 'boolean'],
        ];
    }
}
