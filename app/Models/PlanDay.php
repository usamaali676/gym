<?php

namespace App\Models;

use App\Enums\SessionType;
use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanDay extends Model
{
    use RecordsContentChanges;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['session_type' => SessionType::class, 'is_rest' => 'boolean'];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Programs, $this->plan_id, 'upsert']];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlanDayExercise::class)->orderBy('position');
    }

    /** "PUSH, Chest Focus" style heading. */
    public function title(): string
    {
        return $this->focusLabel() === null ? $this->label : "{$this->label}, {$this->focus} Focus";
    }

    /** The focus text to show after the label, or null when it only repeats it ("LEGS Legs", "REST Recovery"). */
    public function focusLabel(): ?string
    {
        return $this->is_rest || strcasecmp($this->label, $this->focus) === 0 ? null : $this->focus;
    }

    public function totalSets(): int
    {
        return (int) $this->items->sum('sets');
    }
}
