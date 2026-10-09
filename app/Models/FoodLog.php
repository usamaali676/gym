<?php

namespace App\Models;

use App\Enums\Meal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'logged_on' => 'date',
            'meal' => Meal::class,
            'quantity' => 'float',
            'grams' => 'float',
            'kcal' => 'float',
            'protein_g' => 'float',
            'carbs_g' => 'float',
            'fat_g' => 'float',
            'fibre_g' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
