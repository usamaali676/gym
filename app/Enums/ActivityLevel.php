<?php

namespace App\Enums;

enum ActivityLevel: string
{
    case Sedentary = 'sedentary';
    case Light = 'light';
    case Moderate = 'moderate';
    case Active = 'active';
    case VeryActive = 'very_active';

    public function label(): string
    {
        return match ($this) {
            self::Sedentary => 'Sedentary (desk job, little exercise)',
            self::Light => 'Lightly active (1-3 sessions a week)',
            self::Moderate => 'Moderately active (3-5 sessions a week)',
            self::Active => 'Very active (6-7 hard sessions a week)',
            self::VeryActive => 'Extremely active (physical job + training)',
        };
    }

    /** Standard Harris-Benedict style multipliers applied to BMR. */
    public function multiplier(): float
    {
        return match ($this) {
            self::Sedentary => 1.2,
            self::Light => 1.375,
            self::Moderate => 1.55,
            self::Active => 1.725,
            self::VeryActive => 1.9,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
