<?php

namespace App\Http\Requests;

use App\Enums\Meal;
use App\Http\Requests\Concerns\ParsesFoodDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Log a whole meal at once (the "Log this meal" button on the diet page). */
class BatchFoodLogRequest extends FormRequest
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
            'logged_on' => $this->loggableDateRules(),
            'meal' => ['required', Rule::in(Meal::values())],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.food_id' => ['required', 'integer', 'exists:foods,id'],
            'items.*.grams' => ['required', 'numeric', 'min:1', 'max:5000'],
        ];
    }
}
