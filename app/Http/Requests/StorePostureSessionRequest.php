<?php

namespace App\Http\Requests;

use App\Enums\CameraView;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostureSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'exercise_id' => ['required', 'integer', Rule::exists('exercises', 'id')->where('is_active', true)],
            'posture_rule_id' => ['nullable', 'integer', Rule::exists('posture_rules', 'id')->where('exercise_id', $this->input('exercise_id'))],
            'workout_session_id' => ['nullable', 'integer', Rule::exists('workout_sessions', 'id')->where('user_id', $this->user()?->id)],
            'rule_version' => ['required', 'integer', 'min:1', 'max:1000'],
            'camera_view' => ['required', Rule::in(CameraView::values())],
            'started_at' => ['required', 'date', 'before_or_equal:+10 minutes', 'after:-2 days'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'total_reps' => ['required', 'integer', 'min:0', 'max:500'],
            'good_reps' => ['required', 'integer', 'min:0', 'lte:total_reps'],
            'bad_reps' => ['required', 'integer', 'min:0', 'lte:total_reps'],
            'hold_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'form_score_avg' => ['required', 'integer', 'between:0,100'],
            'form_score_min' => ['required', 'integer', 'between:0,100', 'lte:form_score_avg'],
            'error_counts' => ['nullable', 'array', 'max:40'],
            'error_counts.*' => ['integer', 'min:0', 'max:500'],
            'rep_log' => ['nullable', 'array', 'max:200'],
            'rep_log.*.n' => ['required', 'integer', 'min:1', 'max:500'],
            'rep_log.*.ms' => ['required', 'integer', 'min:0', 'max:3600000'],
            'rep_log.*.score' => ['required', 'integer', 'between:0,100'],
            'rep_log.*.good' => ['required', 'boolean'],
            'rep_log.*.partial' => ['nullable', 'boolean'],
            'rep_log.*.hold' => ['nullable', 'boolean'],
            'rep_log.*.rom' => ['nullable', 'numeric', 'between:0,3'],
            'rep_log.*.errors' => ['nullable', 'array', 'max:12'],
            'rep_log.*.errors.*' => ['string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'avg_fps' => ['nullable', 'numeric', 'between:0,240'],
            'model_variant' => ['nullable', Rule::in(['lite', 'full', 'heavy'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $data = $this->all();

            if ((int) ($data['good_reps'] ?? 0) + (int) ($data['bad_reps'] ?? 0) !== (int) ($data['total_reps'] ?? 0)) {
                $v->errors()->add('total_reps', 'Good and bad reps must add up to the total.');
            }
            if ((int) ($data['total_reps'] ?? 0) === 0 && (int) ($data['hold_seconds'] ?? 0) === 0) {
                $v->errors()->add('total_reps', 'There is nothing to save: no reps or holds were counted.');
            }
            // The counting happens on the member's own device, so the server cannot check it; it can refuse numbers that are
            // impossible (a modified app posting 500 reps for a 5-second set to earn a badge). The fastest honest exercise
            // (jumping jacks) is about one rep a second; two a second is already generous.
            $duration = (int) ($data['duration_seconds'] ?? 0);
            if ($duration > 0 && (int) ($data['total_reps'] ?? 0) > 2 * $duration + 2) {
                $v->errors()->add('total_reps', 'More reps than the time allows. Check the set and try again.');
            }
            foreach (array_keys($data['error_counts'] ?? []) as $key) {
                if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                    $v->errors()->add('error_counts', 'Error ids must be short lowercase names.');
                    break;
                }
            }
        });
    }
}
