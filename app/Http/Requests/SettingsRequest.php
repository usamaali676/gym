<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sound_enabled' => ['sometimes', 'boolean'],
            'voice_coach' => ['sometimes', 'boolean'],
            'leaderboard_opt_in' => ['sometimes', 'boolean'],
            'ai_coach_opt_in' => ['sometimes', 'boolean'],
            'public_name' => ['nullable', 'string', 'max:60'],
            'water_goal_ml' => ['nullable', 'integer', 'between:500,8000'],
            'sleep_goal_hours' => ['nullable', 'numeric', 'between:4,12'],
            'creatine_dose_g' => ['nullable', 'numeric', 'between:1,20'],
            'workout_reminder_at' => ['nullable', 'date_format:H:i'],
            'creatine_reminder_at' => ['nullable', 'date_format:H:i'],
            'sleep_reminder_at' => ['nullable', 'date_format:H:i'],
            'water_reminders' => ['sometimes', 'boolean'],
            'water_interval_minutes' => ['nullable', 'integer', 'between:20,360'],
            'browser_notifications' => ['sometimes', 'boolean'],
            'email_digest' => ['sometimes', 'boolean'],
            'digest_day' => ['nullable', 'integer', 'between:1,7'],
        ];
    }
}
