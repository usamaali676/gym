<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressionTarget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_weight_kg' => 'float', 'computed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanDayExercise::class, 'plan_day_exercise_id');
    }
}
