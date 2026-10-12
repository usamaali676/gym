<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The browser's resume state for a running workout (position and timer timestamps). */
class SessionStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'array'],
            'state.idx' => ['required', 'integer', 'between:0,60'],
            'state.setIdx' => ['required', 'integer', 'between:0,60'],
            'state.phase' => ['required', 'in:intro,work,rest,finish'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The state is an opaque blob for the client; keep it small.
                if (strlen(json_encode($this->input('state'))) > 4096) {
                    $validator->errors()->add('state', 'State is too large.');
                }
            },
        ];
    }
}
