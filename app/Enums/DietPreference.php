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

    /** How permissive the diet is: vegan 0 < vegetarian 1 < eggetarian 2 < non-vegetarian 3. A food is allowed if its rank is at most the user's. */
    public function rank(): int
    {
        return match ($this) {
            self::Vegan => 0,
            self::Vegetarian => 1,
            self::Eggetarian => 2,
            self::NonVeg => 3,
        };
    }

    /** Diet types of foods this preference may eat, e.g. vegetarian => [vegan, vegetarian]. @return list<string> */
    public function allowedFoodTypes(): array
    {
        return array_values(array_map(fn (self $d) => $d->value, array_filter(self::cases(), fn (self $d) => $d->rank() <= $this->rank())));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
