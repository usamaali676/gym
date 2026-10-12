<?php

namespace App\Enums;

enum ExerciseKind: string
{
    case Plan = 'plan';
    case Alternative = 'alternative';
    case Helper = 'helper';
    case Mobility = 'mobility';

    public function label(): string
    {
        return match ($this) {
            self::Plan => 'Plan',
            self::Alternative => 'Alternative',
            self::Helper => 'Warm-up helper',
            self::Mobility => 'Mobility',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
