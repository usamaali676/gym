<?php

namespace App\Models;

use App\Enums\SessionSource;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'source' => SessionSource::class,
            'scheduled_for' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'volume_kg' => 'float',
            'autosave_state' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planDay(): BelongsTo
    {
        return $this->belongsTo(PlanDay::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(SessionExercise::class)->orderBy('position');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(SessionSet::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [SessionStatus::InProgress, SessionStatus::Paused], true);
    }
}
