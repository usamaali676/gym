<?php

namespace App\Models;

use App\Models\Concerns\RecordsContentChanges;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Food extends Model
{
    use RecordsContentChanges;

    protected $table = 'foods';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kcal_100g' => 'float',
            'protein_100g' => 'float',
            'carbs_100g' => 'float',
            'fat_100g' => 'float',
            'fibre_100g' => 'float',
            'is_public' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    /** Only the shared database is synced; foods people add for themselves are private to them. */
    public function contentChangeTargets(string $event): array
    {
        if ($this->user_id !== null) {
            return [];
        }

        return [[ContentCollection::Foods, $this->id, $event === 'deleted' ? 'delete' : 'upsert']];
    }

    public function servings(): HasMany
    {
        return $this->hasMany(FoodServing::class)->orderByDesc('is_default');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Foods a given user may see: global/public ones plus their own custom foods. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('user_id')->orWhere('is_public', true);
            if ($user) {
                $q->orWhere('user_id', $user->id);
            }
        });
    }

    /** Normalised form used for the indexed autocomplete column. */
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
