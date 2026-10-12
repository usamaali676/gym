<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\ExerciseKind;
use App\Enums\Mechanic;
use App\Enums\MuscleGroup;
use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    use HasFactory, RecordsContentChanges;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // `is_active` (the website's switch) and `status` (the richer workflow state) never disagree.
        static::saving(function (Exercise $exercise) {
            if ($exercise->isDirty('status')) {
                $exercise->is_active = $exercise->status === 'published';
            } elseif ($exercise->isDirty('is_active')) {
                $exercise->status = $exercise->is_active ? 'published' : ($exercise->status === 'published' ? 'archived' : $exercise->status);
            }
            if ($exercise->status === 'published' && ! $exercise->published_at) {
                $exercise->published_at = now();
            }
        });
    }

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
            'per_side' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The exercise document, plus every program that lists the exercise (programs embed its name).
     *
     * @return list<array{0: ContentCollection, 1: int, 2: string}>
     */
    public function contentChangeTargets(string $event): array
    {
        $targets = [[ContentCollection::Exercises, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];

        $planIds = PlanDayExercise::query()->where('exercise_id', $this->id)
            ->join('plan_days', 'plan_days.id', '=', 'plan_day_exercises.plan_day_id')
            ->distinct()->pluck('plan_days.plan_id');
        foreach ($planIds as $planId) {
            $targets[] = [ContentCollection::Programs, (int) $planId, 'upsert'];
        }

        return $targets;
    }

    public function media(): HasMany
    {
        return $this->hasMany(ExerciseMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
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
