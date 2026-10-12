<?php

namespace App\Http\Resources\V1;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Profile */
class ProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'age' => $this->age,
            'sex' => $this->sex?->value,
            'height_cm' => $this->height_cm,
            'weight_kg' => $this->weight_kg,
            'target_weight_kg' => $this->target_weight_kg,
            'activity_level' => $this->activity_level?->value,
            'fitness_level' => $this->fitness_level?->value,
            'goal' => $this->goal?->value,
            'diet_preference' => $this->diet_preference?->value,
            'equipment' => $this->equipment ?? [],
            'training_days' => $this->trainingDays(),
            'unit_system' => $this->unit_system?->value ?? 'metric',
            'timezone' => $this->timezone,
            'plan_id' => $this->plan_id,
            'plan_started_on' => $this->plan_started_on?->toDateString(),
            'public_name' => $this->public_name,
        ];
    }
}
