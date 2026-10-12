<?php

namespace App\Enums;

enum UnitSystem: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';

    public function label(): string
    {
        return match ($this) {
            self::Metric => 'Metric (kg, cm)',
            self::Imperial => 'Imperial (lb, ft)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
