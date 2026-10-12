<?php

namespace App\Enums;

enum MuscleGroup: string
{
    case Chest = 'chest';
    case Back = 'back';
    case Shoulders = 'shoulders';
    case Biceps = 'biceps';
    case Triceps = 'triceps';
    case Quads = 'quads';
    case Hamstrings = 'hamstrings';
    case Glutes = 'glutes';
    case Calves = 'calves';
    case Traps = 'traps';
    case Forearms = 'forearms';
    case Core = 'core';
    case FullBody = 'full_body';

    public function label(): string
    {
        return match ($this) {
            self::Chest => 'Chest',
            self::Back => 'Back',
            self::Shoulders => 'Shoulders',
            self::Biceps => 'Biceps',
            self::Triceps => 'Triceps',
            self::Quads => 'Quads',
            self::Hamstrings => 'Hamstrings',
            self::Glutes => 'Glutes',
            self::Calves => 'Calves',
            self::Traps => 'Traps',
            self::Forearms => 'Forearms',
            self::Core => 'Core',
            self::FullBody => 'Full body',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
