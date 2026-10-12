<?php

namespace App\Http\Resources\V1;

use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A food and its serving sizes. Values are per 100 g; the server converts quantities (150 g x 20 g/100 g = 30 g)
 * and stores the result, so apps display what the server computed instead of re-deriving it.
 *
 * @mixin Food
 */
class FoodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'diet_type' => $this->diet_type,
            'is_verified' => (bool) $this->is_verified,
            'custom' => $this->user_id !== null,
            'per_100g' => [
                'kcal' => $this->kcal_100g,
                'protein_g' => $this->protein_100g,
                'carbs_g' => $this->carbs_100g,
                'fat_g' => $this->fat_100g,
                'fibre_g' => $this->fibre_100g,
            ],
            'servings' => $this->servings->map(fn ($s) => ['id' => $s->id, 'label' => $s->label, 'grams' => $s->grams, 'is_default' => (bool) $s->is_default])->values()->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
