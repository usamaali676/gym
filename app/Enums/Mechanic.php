<?php

namespace App\Enums;

enum Mechanic: string
{
    case Compound = 'compound';
    case Isolation = 'isolation';

    public function label(): string
    {
        return match ($this) {
            self::Compound => 'Compound',
            self::Isolation => 'Isolation',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
