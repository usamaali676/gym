<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id', 'workout_reminder_at', 'water_reminders', 'water_interval_minutes', 'creatine_reminder_at',
        'sleep_reminder_at', 'browser_notifications', 'email_digest', 'digest_day',
    ];

    protected function casts(): array
    {
        return [
            'water_reminders' => 'boolean',
            'browser_notifications' => 'boolean',
            'email_digest' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
