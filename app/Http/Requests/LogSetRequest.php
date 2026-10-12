<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LogSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_exercise_id' => ['required', 'integer'],
            'set_number' => ['required', 'integer', 'between:1,30'],
            'weight_kg' => ['nullable', 'numeric', 'between:0,1000'],
            'reps' => ['nullable', 'integer', 'between:0,500'],
            'duration_seconds' => ['nullable', 'integer', 'between:0,14400'],
            'rpe' => ['nullable', 'numeric', 'between:1,10'],
            'is_warmup' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('reps') === null && $this->input('duration_seconds') === null) {
                    $validator->errors()->add('reps', 'Log the reps or the time held.');
                }
            },
        ];
    }
}
