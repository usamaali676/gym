<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use RecordsContentChanges;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_default' => 'boolean', 'published_at' => 'datetime'];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Programs, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProgramPhase::class)->orderBy('sort_order')->orderBy('starts_week');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function days(): HasMany
    {
        return $this->hasMany(PlanDay::class)->orderBy('day_of_week');
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }
}
