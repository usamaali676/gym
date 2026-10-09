<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'exercise_id' => ['required', 'integer', 'exists:exercises,id'],
            'permanent' => ['sometimes', 'boolean'],
        ];
    }
}
