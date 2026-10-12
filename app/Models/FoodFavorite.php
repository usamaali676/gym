<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodFavorite extends Model
{
    protected $guarded = ['id'];

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
