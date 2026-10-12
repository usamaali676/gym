<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Release notes history shown on the "What's new" screen. */
class AppRelease extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime'];
    }
}
