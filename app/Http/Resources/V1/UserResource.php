<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as the mobile app sees them. Only fields the owner may read: no password hash,
 * remember token, internal flags or other users' data.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_guest' => (bool) $this->is_guest,
            'role' => $this->role->value,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'onboarded' => $this->isOnboarded(),
            'level' => $this->level,
            'xp_total' => $this->xp_total,
            'workout_streak' => $this->workout_streak,
            'longest_workout_streak' => $this->longest_workout_streak,
            'created_at' => $this->created_at?->toIso8601String(),
            'profile' => $this->whenLoaded('profile', fn () => $this->profile ? new ProfileResource($this->profile) : null),
        ];
    }
}
