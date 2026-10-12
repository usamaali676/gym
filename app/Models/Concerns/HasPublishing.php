<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Draft / published / archived workflow plus an optional schedule window and plan audience.
 * Expects `status`, `published_at` and (optionally) `starts_at`, `ends_at`, `audience` columns.
 */
trait HasPublishing
{
    public static function bootHasPublishing(): void
    {
        static::saving(function ($model) {
            if ($model->status === 'published' && ! $model->published_at) {
                $model->published_at = now();
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Inside the schedule window (a missing bound is open-ended). */
    public function scopeLiveNow(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** Null audience = everyone; otherwise the member's plan slug must be listed. */
    public function scopeForAudience(Builder $query, ?string $planSlug): Builder
    {
        return $query->where(function (Builder $q) use ($planSlug) {
            $q->whereNull('audience');
            if ($planSlug !== null) {
                $q->orWhereJsonContains('audience', $planSlug);
            }
        });
    }
}
