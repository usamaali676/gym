<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'client_created_at' => 'datetime'];
    }
}
