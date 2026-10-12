<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_default' => 'boolean'];
    }

    public function days(): HasMany
    {
        return $this->hasMany(PlanDay::class)->orderBy('day_of_week');
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }
}
