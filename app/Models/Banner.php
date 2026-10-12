<?php

namespace App\Models;

use App\Models\Concerns\HasPublishing;
use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Banner extends Model
{
    use HasPublishing, RecordsContentChanges;

    public const PLACEMENTS = ['home', 'programs', 'library'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleted(function (Banner $banner) {
            if ($banner->image_path) {
                Storage::disk('media')->delete($banner->image_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Banners, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];
    }

    public function scopeVisibleTo(Builder $query, ?string $planSlug): Builder
    {
        return $query->published()->liveNow()->forAudience($planSlug);
    }
}
