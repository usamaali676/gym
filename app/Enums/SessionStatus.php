<?php

namespace App\Enums;

enum SessionStatus: string
{
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Skipped => 'Skipped',
            self::Abandoned => 'Abandoned',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
