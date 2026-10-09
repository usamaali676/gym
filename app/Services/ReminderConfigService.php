<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\WorkoutSession;

/**
 * What the in-page reminder scheduler needs. Reminders are browser notifications raised while IronPulse is open
 * (there is no push server), so this only returns a config when the user has switched them on.
 */
class ReminderConfigService
{
    public function __construct(private readonly PlanService $plans) {}

    /** @return array<string, mixed>|null */
    public function forUser(User $user): ?array
    {
        $settings = NotificationSetting::where('user_id', $user->id)->first();
        if (! $settings?->browser_notifications) {
            return null;
        }

        $today = $user->localToday();
        $user->loadMissing('profile');
        $trainedToday = WorkoutSession::where('user_id', $user->id)->whereDate('scheduled_for', $today->toDateString())->where('status', 'completed')->exists();
        $time = fn (?string $t) => $t ? substr($t, 0, 5) : null;

        return [
            'tz' => $user->timezone(),
            'today' => $today->toDateString(),
            'workout' => ($this->plans->isTrainingDay($user, $today) && ! $trainedToday) ? $time($settings->workout_reminder_at) : null,
            'creatine' => $time($settings->creatine_reminder_at),
            'sleep' => $time($settings->sleep_reminder_at),
            'water' => $settings->water_reminders ? max(30, (int) $settings->water_interval_minutes) : null,
            'urls' => [
                'workout' => route('workout.start'),
                'habits' => route('recovery.index'),
                'state' => url('/api/habits'),
            ],
        ];
    }
}
