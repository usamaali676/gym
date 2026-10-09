<?php

namespace App\Http\Requests\Admin;

use App\Enums\SessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:20'],
            'session_type' => ['required', Rule::in(SessionType::values())],
            'label' => ['required', 'string', 'max:30'],
            'focus' => ['required', 'string', 'max:60'],
            'is_rest' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'recovery_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
