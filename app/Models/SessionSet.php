<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionSet extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'rpe' => 'float',
            'is_warmup' => 'boolean',
            'is_pr' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function sessionExercise(): BelongsTo
    {
        return $this->belongsTo(SessionExercise::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function volume(): float
    {
        return (float) ($this->weight_kg ?? 0) * (int) ($this->reps ?? 0);
    }
}
