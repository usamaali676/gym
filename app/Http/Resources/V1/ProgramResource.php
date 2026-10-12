<?php

namespace App\Http\Resources\V1;

use App\Models\Plan;
use App\Services\Membership\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A program (training plan) card. Detail (phases, days, exercises) is added by ProgramDetailResource, and only for
 * programs the caller's plan includes.
 *
 * @mixin Plan
 */
class ProgramResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locked = self::isLocked($request, $this->resource);
        $profilePlanId = $request->user()?->profile?->plan_id;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'source' => $this->source,
            'version' => (int) $this->version,
            'is_default' => (bool) $this->is_default,
            'is_selected' => $profilePlanId !== null && $profilePlanId === $this->id,
            'required_entitlement' => $this->required_entitlement,
            'locked' => $locked,
            'training_days' => $this->whenLoaded('days', fn () => $this->days->where('is_rest', false)->count()),
            'phases_count' => $this->whenLoaded('phases', fn () => $this->phases->where('status', 'published')->count()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public static function isLocked(Request $request, Plan $plan): bool
    {
        return $plan->required_entitlement !== null
            && ! app(EntitlementService::class)->forRequest($request)->allows($plan->required_entitlement);
    }
}
