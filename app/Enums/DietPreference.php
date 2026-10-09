<?php

namespace App\Enums;

enum DietPreference: string
{
    case NonVeg = 'nonveg';
    case Eggetarian = 'eggetarian';
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';

    public function label(): string
    {
        return match ($this) {
            self::NonVeg => 'Non-vegetarian',
            self::Eggetarian => 'Eggetarian',
            self::Vegetarian => 'Vegetarian',
            self::Vegan => 'Vegan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
