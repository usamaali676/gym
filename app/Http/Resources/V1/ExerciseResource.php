<?php

namespace App\Http\Resources\V1;

use App\Models\Exercise;
use App\Services\Membership\EntitlementService;
use App\Support\RestTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One exercise. A premium exercise the caller's plan does not include is returned as a teaser (name, muscle group,
 * `locked: true`) with no instructions and no media; the server never relies on the app hiding it.
 *
 * Expects `media` and `postureRules` to be eager loaded (lazy loading is disabled outside production).
 *
 * @mixin Exercise
 */
class ExerciseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locked = self::isLocked($request, $this->resource);

        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'muscle_group' => $this->muscle_group->value,
            'muscle_group_label' => $this->muscle_group->label(),
            'secondary_groups' => $this->secondary_groups ?? [],
            'mechanic' => $this->mechanic->value,
            'difficulty' => $this->difficulty->value,
            'equipment' => $this->equipment ?? [],
            'is_timed' => (bool) $this->is_timed,
            'per_side' => (bool) $this->per_side,
            'mobility_type' => $this->mobility_type,
            'required_entitlement' => $this->required_entitlement,
            'locked' => $locked,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($locked) {
            return $data;
        }

        $rule = $this->postureRules->where('is_active', true)->sortByDesc('version')->first();

        return $data + [
            'target_regions' => $this->target_regions ?? ['primary' => [], 'secondary' => []],
            'rest' => [
                'min_seconds' => RestTime::fixed() ?: $this->rest_min_seconds,
                'max_seconds' => RestTime::fixed() ?: $this->rest_max_seconds,
                'default_seconds' => RestTime::fixed() ?: $this->default_rest_seconds,
            ],
            'default_hold_seconds' => $this->default_hold_seconds,
            'description' => $this->description,
            'form_cues' => $this->form_cues ?? [],
            'common_mistakes' => $this->common_mistakes ?? [],
            'breathing' => $this->breathing,
            'demo_key' => $this->demo_key ?: $this->slug,
            'media' => ExerciseMediaResource::collection($this->media->where('status', 'published')->values()),
            'posture' => $rule ? [
                'supported' => true,
                'tier' => $rule->tier->value,
                'camera_view' => $rule->camera_view->value,
                'rule_version' => $rule->version,
            ] : ['supported' => false],
        ];
    }

    public static function isLocked(Request $request, Exercise $exercise): bool
    {
        return $exercise->required_entitlement !== null
            && ! app(EntitlementService::class)->forRequest($request)->allows($exercise->required_entitlement);
    }
}
