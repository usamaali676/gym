<?php

namespace App\Models;

use App\Enums\ActivityLevel;
use App\Enums\DietPreference;
use App\Enums\Goal;
use App\Enums\Sex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DietPlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'goal' => Goal::class,
            'diet_preference' => DietPreference::class,
            'sex' => Sex::class,
            'activity_level' => ActivityLevel::class,
            'calorie_cycling' => 'boolean',
            'is_active' => 'boolean',
            'rest_day_targets' => 'array',
            'height_cm' => 'float',
            'weight_kg' => 'float',
            'protein_g_per_kg' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{calories:int,protein_g:int,carbs_g:int,fat_g:int,fibre_g:int} */
    public function targetsFor(bool $isTrainingDay): array
    {
        if (! $isTrainingDay && $this->calorie_cycling && $this->rest_day_targets) {
            return $this->rest_day_targets;
        }

        return [
            'calories' => $this->calories,
            'protein_g' => $this->protein_g,
            'carbs_g' => $this->carbs_g,
            'fat_g' => $this->fat_g,
            'fibre_g' => $this->fibre_g,
        ];
    }
}
