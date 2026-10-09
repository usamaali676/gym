<?php

namespace App\Policies;

use App\Models\Food;
use App\Models\User;

/** Shared foods are read-only for users (admins edit them in the admin panel); users own the foods they create. */
class FoodPolicy
{
    public function delete(User $user, Food $food): bool
    {
        return $food->user_id !== null && $food->user_id === $user->id;
    }
}
