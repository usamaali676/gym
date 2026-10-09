<?php

namespace App\Models;

use App\Enums\ActivityLevel;
use App\Enums\DietPreference;
use App\Enums\FitnessLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\UnitSystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'public_name', 'age', 'sex', 'height_cm', 'weight_kg', 'target_weight_kg',
        'activity_level', 'fitness_level', 'goal', 'diet_preference', 'equipment', 'days_per_week',
        'training_days', 'unit_system', 'timezone', 'plan_started_on', 'water_goal_ml',
        'sleep_goal_hours', 'creatine_dose_g', 'sound_enabled', 'voice_coach', 'leaderboard_opt_in',
        'ai_coach_opt_in', 'onboarding_step',
    ];

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'activity_level' => ActivityLevel::class,
            'fitness_level' => FitnessLevel::class,
            'goal' => Goal::class,
            'diet_preference' => DietPreference::class,
            'unit_system' => UnitSystem::class,
            'equipment' => 'array',
            'training_days' => 'array',
            'height_cm' => 'float',
            'weight_kg' => 'float',
            'target_weight_kg' => 'float',
            'sleep_goal_hours' => 'float',
            'creatine_dose_g' => 'float',
            'plan_started_on' => 'date',
            'sound_enabled' => 'boolean',
            'voice_coach' => 'boolean',
            'leaderboard_opt_in' => 'boolean',
            'ai_coach_opt_in' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return list<int> ISO weekdays the user trains on. */
    public function trainingDays(): array
    {
        return $this->training_days ?: [1, 2, 3, 4, 5];
    }

    public function waterGoalMl(): int
    {
        if ($this->water_goal_ml) {
            return (int) $this->water_goal_ml;
        }

        return (int) round(($this->weight_kg ?: 70) * config('ironpulse.water.ml_per_kg') / 50) * 50;
    }
}
