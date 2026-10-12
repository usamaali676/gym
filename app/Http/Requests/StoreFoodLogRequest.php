<?php

namespace App\Http\Requests;

use App\Enums\Meal;
use App\Http\Requests\Concerns\ParsesFoodDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFoodLogRequest extends FormRequest
{
    use ParsesFoodDates;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'food_id' => ['required', 'integer', 'exists:foods,id'],
            'logged_on' => $this->loggableDateRules(),
            'meal' => ['required', Rule::in(Meal::values())],
            'quantity' => ['required', 'numeric', 'min:0.1', 'max:5000'],
            'unit' => ['required', Rule::in(['grams', 'serving'])],
            'serving_id' => ['nullable', 'integer', 'required_if:unit,serving', Rule::exists('food_servings', 'id')->where('food_id', $this->input('food_id'))],
        ];
    }
}
