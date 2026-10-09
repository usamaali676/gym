<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\ExerciseKind;
use App\Enums\Mechanic;
use App\Enums\MuscleGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => ExerciseKind::class,
            'muscle_group' => MuscleGroup::class,
            'mechanic' => Mechanic::class,
            'difficulty' => Difficulty::class,
            'secondary_groups' => 'array',
            'target_regions' => 'array',
            'equipment' => 'array',
            'form_cues' => 'array',
            'common_mistakes' => 'array',
            'is_timed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(PlanDayExercise::class);
    }

    public function postureRules(): HasMany
    {
        return $this->hasMany(PostureRule::class);
    }

    public function activePostureRule(): ?PostureRule
    {
        return $this->postureRules->where('is_active', true)->sortByDesc('version')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isCompound(): bool
    {
        return $this->mechanic === Mechanic::Compound;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
