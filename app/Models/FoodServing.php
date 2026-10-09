<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodServing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['grams' => 'float', 'is_default' => 'boolean'];
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
