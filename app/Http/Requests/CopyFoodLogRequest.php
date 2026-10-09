<?php

namespace App\Http\Requests;

use App\Enums\Meal;
use App\Http\Requests\Concerns\ParsesFoodDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CopyFoodLogRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => $this->loggableDateRules(),
            'meal' => ['nullable', Rule::in(Meal::values())],
        ];
    }
}
