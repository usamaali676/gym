<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanDayExercise extends Model
{
    use RecordsContentChanges;

    protected $guarded = ['id'];

    public function contentChangeTargets(string $event): array
    {
        $planId = PlanDay::query()->whereKey($this->plan_day_id)->value('plan_id');

        return $planId ? [[ContentCollection::Programs, (int) $planId, 'upsert']] : [];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(PlanDay::class, 'plan_day_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** The name the PDF prints for this row (e.g. "Lat Pulldown (Wide Grip)"). */
    public function displayName(): string
    {
        return $this->label_override ?: $this->exercise->name;
    }

    public function repsLabel(): string
    {
        return $this->reps_min === $this->reps_max ? (string) $this->reps_min : "{$this->reps_min}-{$this->reps_max}";
    }

    public function restSeconds(): int
    {
        return $this->rest_seconds_override ?: $this->exercise->default_rest_seconds;
    }
}
