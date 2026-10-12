<?php

namespace App\Models;

use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per change to app-visible content. `id` is the sync cursor: it only grows, so a client that remembers
 * the last id it saw can ask for "everything after it" without any dependence on clocks.
 */
class ContentChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['collection', 'entity_id', 'action'];

    public static function record(ContentCollection $collection, int $entityId, string $action = 'upsert'): void
    {
        static::query()->create(['collection' => $collection->value, 'entity_id' => $entityId, 'action' => $action]);
    }

    /** The newest cursor value (0 when nothing has ever changed). */
    public static function latestId(): int
    {
        return (int) static::query()->max('id');
    }
}
