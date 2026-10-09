<?php

namespace App\Models;

use App\Enums\DietPreference;
use App\Enums\Goal;
use App\Enums\Meal;
use Illuminate\Database\Eloquent\Model;

class MealTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'diet_preference' => DietPreference::class,
            'goal' => Goal::class,
            'slot' => Meal::class,
            'items' => 'array',
            'tags' => 'array',
        ];
    }
}
