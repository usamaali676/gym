<?php

namespace App\Models;

use App\Models\Concerns\HasPublishing;
use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;

class HelpArticle extends Model
{
    use HasPublishing, RecordsContentChanges;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function contentChangeTargets(string $event): array
    {
        return [[ContentCollection::Help, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];
    }
}
