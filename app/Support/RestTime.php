<?php

namespace App\Support;

/**
 * The rest between sets. The gym runs one rest time for every exercise (20 seconds by default); the plan data's own
 * per-exercise values are only used when `ironpulse.rest_seconds` is set to 0. The default is also given here, so a
 * server that still has an older cached config answers 20 as well.
 */
final class RestTime
{
    public const DEFAULT = 20;

    /** The fixed rest in seconds, or 0 when each exercise keeps its own. */
    public static function fixed(): int
    {
        return max(0, (int) config('ironpulse.rest_seconds', self::DEFAULT));
    }

    /** "20 s" while a fixed rest is on, otherwise null (the caller builds its own label). */
    public static function label(): ?string
    {
        $fixed = self::fixed();

        return $fixed > 0 ? "{$fixed} s" : null;
    }
}
