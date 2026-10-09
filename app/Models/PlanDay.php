<?php

namespace App\Models;

use App\Enums\SessionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanDay extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['session_type' => SessionType::class, 'is_rest' => 'boolean'];
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
        return $this->is_rest ? 'REST' : "{$this->label}, {$this->focus} Focus";
    }

    public function totalSets(): int
    {
        return (int) $this->items->sum('sets');
    }
}
