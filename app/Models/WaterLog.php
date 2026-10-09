<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaterLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['logged_on' => 'date', 'logged_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
