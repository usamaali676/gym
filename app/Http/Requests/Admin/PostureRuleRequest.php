<?php

namespace App\Http\Requests\Admin;

use App\Enums\CameraView;
use App\Enums\PostureTier;
use App\Services\PostureRuleValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostureRuleRequest extends FormRequest
{
    /** Decoded config and guidance, available after validation. */
    public ?array $config = null;

    public ?array $guidance = null;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tier' => ['required', Rule::in(PostureTier::values())],
            'camera_view' => ['required', Rule::in(CameraView::values())],
            'config_json' => ['required', 'string', 'max:60000'],
            'guidance_json' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $config = json_decode($this->input('config_json'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $v->errors()->add('config_json', 'Config is not valid JSON: '.json_last_error_msg().'.');

                return;
            }
            // The same checks the browser's rule engine applies, so a rule that saves here always runs in the lab.
            $problems = app(PostureRuleValidator::class)->validate($config);
            foreach ($problems as $problem) {
                $v->errors()->add('config_json', $problem);
            }

            $guidanceRaw = trim((string) $this->input('guidance_json'));
            $guidance = null;
            if ($guidanceRaw !== '') {
                $guidance = json_decode($guidanceRaw, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($guidance)) {
                    $v->errors()->add('guidance_json', 'Guidance must be a JSON object.');

                    return;
                }
            }

            if ($problems === []) {
                $this->config = $config;
                $this->guidance = $guidance;
            }
        });
    }
}
