<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Account details (name + email). Guests have no email until they claim the account. */
class ProfileUpdateRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                $this->user()->is_guest ? 'prohibited' : 'required',
                'string',
                'lowercase',
                'email',
                'max:190',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
