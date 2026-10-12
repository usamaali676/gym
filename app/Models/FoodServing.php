<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodServing extends Model
{
    use RecordsContentChanges;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['grams' => 'float', 'is_default' => 'boolean'];
    }

    public function contentChangeTargets(string $event): array
    {
        $shared = Food::query()->whereKey($this->food_id)->whereNull('user_id')->exists();

        return $shared ? [[ContentCollection::Foods, $this->food_id, 'upsert']] : [];
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
