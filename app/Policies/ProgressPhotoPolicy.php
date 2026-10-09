<?php

namespace App\Policies;

use App\Models\ProgressPhoto;
use App\Models\User;

class ProgressPhotoPolicy
{
    public function view(User $user, ProgressPhoto $photo): bool
    {
        return $photo->user_id === $user->id;
    }

    public function delete(User $user, ProgressPhoto $photo): bool
    {
        return $photo->user_id === $user->id;
    }
}
