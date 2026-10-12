<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionExercise extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_weight_kg' => 'float'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function originalExercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'original_exercise_id');
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanDayExercise::class, 'plan_day_exercise_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(SessionSet::class)->orderBy('set_number');
    }
}
