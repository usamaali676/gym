<?php

namespace App\Enums;

enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
    case Unspecified = 'unspecified';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Unspecified => 'Prefer not to say',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
