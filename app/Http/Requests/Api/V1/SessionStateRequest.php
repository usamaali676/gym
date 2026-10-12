<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The app's resume state for a running workout. `idx`, `setIdx` and `phase` are shared with the website's player;
 * the timestamps let the app rebuild every timer after a phone call, backgrounding or a restart.
 */
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
            'state.phase' => ['required', 'in:intro,work,rest,paused,finish'],
            // Absolute moments (ISO 8601), never "seconds left": a countdown is recomputed from these.
            'state.rest_ends_at' => ['nullable', 'date'],
            'state.paused_at' => ['nullable', 'date'],
            'state.paused_ms' => ['nullable', 'integer', 'between:0,86400000'],
            'state.active_ms' => ['nullable', 'integer', 'between:0,86400000'],
            'state.saved_at' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (strlen(json_encode($this->input('state'))) > 4096) {
                    $validator->errors()->add('state', 'State is too large.');
                }
            },
        ];
    }
}
