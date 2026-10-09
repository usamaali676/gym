<?php

namespace App\Policies;

use App\Models\FoodLog;
use App\Models\User;

class FoodLogPolicy
{
    public function update(User $user, FoodLog $log): bool
    {
        return $log->user_id === $user->id;
    }

    public function delete(User $user, FoodLog $log): bool
    {
        return $log->user_id === $user->id;
    }
}
