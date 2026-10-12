<?php

namespace App\Enums;

enum SessionSource: string
{
    case Game = 'game';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Game => 'Game mode',
            self::Manual => 'Marked manually',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
