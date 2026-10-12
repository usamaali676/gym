<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A dashboard-managed switch or text the apps read. Only keys registered in App\Support\AppSettingKeys exist. */
class AppSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'json', 'is_public' => 'boolean'];
    }
}
