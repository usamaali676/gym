<?php

namespace App\Policies;

use App\Models\PlanDayExercise;
use App\Models\User;

class PlanDayExercisePolicy
{
    /** A user may only change rows that belong to the plan they follow. */
    public function swap(User $user, PlanDayExercise $item): bool
    {
        $planId = $user->profile?->plan_id;

        return $planId !== null && $item->day()->where('plan_id', $planId)->exists();
    }
}
