<?php

namespace App\Http\Requests;

use App\Enums\Meal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFoodLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.1', 'max:5000'],
            'unit' => ['required', Rule::in(['grams', 'serving'])],
            'serving_id' => ['nullable', 'integer', 'required_if:unit,serving', 'exists:food_servings,id'],
            'meal' => ['nullable', Rule::in(Meal::values())],
        ];
    }
}
