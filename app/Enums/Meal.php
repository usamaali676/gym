<?php

namespace App\Enums;

enum Meal: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Snack = 'snack';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Breakfast',
            self::Lunch => 'Lunch',
            self::Snack => 'Snack',
            self::Dinner => 'Dinner',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
