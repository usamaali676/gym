<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExerciseMedia extends Model
{
    use RecordsContentChanges;

    protected $table = 'exercise_media';

    public const KINDS = ['demo_video', 'instruction_video', 'image', 'thumbnail'];

    public const VIEWS = ['angle', 'side', 'front'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // The file goes with its row (a replaced or deleted demo must not linger on disk).
        static::deleted(fn (ExerciseMedia $media) => Storage::disk($media->disk)->delete($media->path));
    }

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'duration_ms' => 'integer'];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Exercises, $this->exercise_id, 'upsert']];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime, 'video/');
    }
}
