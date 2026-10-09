<?php

namespace App\Enums;

enum SessionType: string
{
    case Push = 'push';
    case Pull = 'pull';
    case Legs = 'legs';
    case Rest = 'rest';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push',
            self::Pull => 'Pull',
            self::Legs => 'Legs',
            self::Rest => 'Rest',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
