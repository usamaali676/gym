<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sprint or phase of a program, covering a range of weeks counted from the member's program start date. */
class ProgramPhase extends Model
{
    use RecordsContentChanges;

    public const KINDS = ['sprint', 'phase'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'starts_week' => 'integer', 'ends_week' => 'integer'];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Programs, $this->plan_id, 'upsert']];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function covers(int $week): bool
    {
        return $week >= $this->starts_week && ($this->ends_week === null || $week <= $this->ends_week);
    }
}
