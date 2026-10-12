<?php

namespace App\Models;

use App\Models\Concerns\HasPublishing;
use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasPublishing, RecordsContentChanges;

    public const SEVERITIES = ['info', 'promo', 'warning', 'maintenance'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'pinned' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Announcements, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];
    }

    /** Published, inside its schedule window and meant for people on the given plan. */
    public function scopeVisibleTo(Builder $query, ?string $planSlug): Builder
    {
        return $query->published()->liveNow()->forAudience($planSlug);
    }
}
