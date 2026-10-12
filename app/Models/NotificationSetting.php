<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id', 'workout_reminder_at', 'water_reminders', 'water_interval_minutes', 'creatine_reminder_at',
        'sleep_reminder_at', 'browser_notifications', 'email_digest', 'digest_day',
        'push_workout_reminders', 'push_membership', 'push_achievements', 'push_announcements', 'push_nutrition',
        'quiet_hours_start', 'quiet_hours_end', 'lock_screen_details',
    ];

    protected function casts(): array
    {
        return [
            'water_reminders' => 'boolean',
            'browser_notifications' => 'boolean',
            'email_digest' => 'boolean',
            'push_workout_reminders' => 'boolean',
            'push_membership' => 'boolean',
            'push_achievements' => 'boolean',
            'push_announcements' => 'boolean',
            'push_nutrition' => 'boolean',
            'lock_screen_details' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
