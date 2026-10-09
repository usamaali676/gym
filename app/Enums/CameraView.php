<?php

namespace App\Enums;

enum CameraView: string
{
    case Side = 'side';
    case Front = 'front';
    case ThreeQuarter = 'three_quarter';

    public function label(): string
    {
        return match ($this) {
            self::Side => 'Side-on',
            self::Front => 'Front-on',
            self::ThreeQuarter => '45 degrees',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
