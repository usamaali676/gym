<?php

namespace App\Http\Resources\V1;

use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The badge catalogue entry. Whether a member earned it is separate, backend-decided state (see /achievements). */
/** @mixin Badge */
class BadgeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'category' => $this->category,
            'tier' => $this->tier,
            'xp_reward' => (int) $this->xp_reward,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
