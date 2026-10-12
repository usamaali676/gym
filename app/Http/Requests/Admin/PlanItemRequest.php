<?php

namespace App\Http\Requests\Admin;

use App\Models\PlanDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var PlanDay|null $day */
        $day = $this->route('day');
        $item = $this->route('item');

        return [
            // An exercise can appear once per day (the plan is a list of lifts, not a log).
            'exercise_id' => ['required', 'integer', 'exists:exercises,id',
                Rule::unique('plan_day_exercises', 'exercise_id')->where('plan_day_id', $day?->id ?? $item?->plan_day_id)->ignore($item?->id)],
            'sets' => ['required', 'integer', 'between:1,10'],
            'reps_min' => ['required', 'integer', 'between:1,100'],
            'reps_max' => ['required', 'integer', 'between:1,100', 'gte:reps_min'],
            'focus' => ['required', 'string', 'max:120'],
            'label_override' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'rest_seconds_override' => ['nullable', 'integer', 'between:10,600'],
        ];
    }
}
