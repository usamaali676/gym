<?php

namespace App\Models\Concerns;

use App\Models\ContentChange;
use App\Support\Content\ContentCollection;

/**
 * Appends to the content change feed whenever the model (or a child that belongs to a synced document) is saved or
 * deleted. Models say which documents they affect in contentChangeTargets().
 *
 * Note: bulk `Model::query()->update()` bypasses model events and therefore the feed; save models individually.
 */
trait RecordsContentChanges
{
    public static function bootRecordsContentChanges(): void
    {
        static::saved(fn ($model) => $model->recordContentChanges('saved'));
        static::deleted(fn ($model) => $model->recordContentChanges('deleted'));
    }

    /**
     * @param  'saved'|'deleted'  $event
     * @return list<array{0: ContentCollection, 1: int, 2: string}> [collection, entity id, 'upsert'|'delete']
     */
    abstract public function contentChangeTargets(string $event): array;

    public function recordContentChanges(string $event): void
    {
        foreach ($this->contentChangeTargets($event) as [$collection, $id, $action]) {
            ContentChange::record($collection, (int) $id, $action);
        }
    }
}
