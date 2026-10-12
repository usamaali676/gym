<?php

namespace App\Models;

use App\Enums\CameraView;
use App\Enums\PostureTier;
use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostureRule extends Model
{
    use RecordsContentChanges;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tier' => PostureTier::class,
            'camera_view' => CameraView::class,
            'config' => 'array',
            'guidance' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** The exercise document embeds which rule version the camera coach uses. */
    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Exercises, $this->exercise_id, 'upsert']];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
