<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Measurement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['measured_on' => 'date', 'weight_kg' => 'float', 'body_fat_pct' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
