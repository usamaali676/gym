<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use RecordsContentChanges;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'is_active' => 'boolean'];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Badges, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];
    }
}
