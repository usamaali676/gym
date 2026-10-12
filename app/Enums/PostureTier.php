<?php

namespace App\Enums;

enum PostureTier: string
{
    case Full = 'full';
    case Simplified = 'simplified';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full rules',
            self::Simplified => 'Simplified',
            self::Generic => 'Warm-up helper',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
