<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Coach\CoachService;

/** Preferences, goals and reminder times. Shared by the web settings form and the mobile API. */
class SettingsService
{
    public const PROFILE_KEYS = ['sound_enabled', 'voice_coach', 'leaderboard_opt_in', 'ai_coach_opt_in', 'public_name', 'water_goal_ml', 'sleep_goal_hours', 'creatine_dose_g'];

    public const NOTIFICATION_KEYS = ['workout_reminder_at', 'creatine_reminder_at', 'sleep_reminder_at', 'water_reminders', 'water_interval_minutes', 'browser_notifications', 'email_digest', 'digest_day'];

    /** @param  array<string, mixed>  $data  validated SettingsRequest data */
    public function update(User $user, array $data): void
    {
        $user->profile->fill(array_intersect_key($data, array_flip(self::PROFILE_KEYS)));
        // Switching the AI coach off takes the saved conversation with it.
        $coachSwitchedOff = $user->profile->isDirty('ai_coach_opt_in') && ! $user->profile->ai_coach_opt_in;
        $user->profile->save();
        if ($coachSwitchedOff) {
            app(CoachService::class)->clear($user);
        }

        NotificationSetting::updateOrCreate(
            ['user_id' => $user->id],
            array_intersect_key($data, array_flip(self::NOTIFICATION_KEYS)),
        );
    }
}
