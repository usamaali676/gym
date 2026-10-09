<?php

namespace App\Enums;

enum Goal: string
{
    case Cutting = 'cutting';
    case Bulking = 'bulking';
    case Maintenance = 'maintenance';
    case Recomposition = 'recomposition';

    public function label(): string
    {
        return match ($this) {
            self::Cutting => 'Cutting (fat loss)',
            self::Bulking => 'Bulking (muscle gain)',
            self::Maintenance => 'Maintenance',
            self::Recomposition => 'Body recomposition',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
