<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersionPolicy extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['maintenance_enabled' => 'boolean', 'maintenance_ends_at' => 'datetime'];
    }

    public static function forPlatform(string $platform): ?self
    {
        return static::query()->where('platform', $platform)->first();
    }

    public function maintenanceActive(): bool
    {
        return $this->maintenance_enabled
            && ($this->maintenance_ends_at === null || $this->maintenance_ends_at->isFuture());
    }
}
