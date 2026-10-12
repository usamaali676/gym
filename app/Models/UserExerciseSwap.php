<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserExerciseSwap extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_permanent' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanDayExercise::class, 'plan_day_exercise_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'replacement_exercise_id');
    }
}
