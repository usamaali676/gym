<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'creatine_taken' => 'boolean',
            'creatine_taken_at' => 'datetime',
            'sleep_hours' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
