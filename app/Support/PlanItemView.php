<?php

namespace App\Support;

use App\Models\Exercise;
use App\Models\PlanDayExercise;
use App\Models\UserExerciseSwap;

/** A plan row as one user sees it: the plan's exercise, or the user's swapped-in replacement. */
final class PlanItemView
{
    public readonly Exercise $exercise;

    public function __construct(public readonly PlanDayExercise $item, public readonly ?UserExerciseSwap $swap = null)
    {
        $this->exercise = $swap?->replacement ?? $item->exercise;
    }

    public function swapped(): bool
    {
        return $this->swap !== null;
    }

    public function original(): Exercise
    {
        return $this->item->exercise;
    }

    public function name(): string
    {
        return $this->swapped() ? $this->exercise->name : $this->item->displayName();
    }

    public function restSeconds(): int
    {
        return $this->item->rest_seconds_override ?: $this->exercise->default_rest_seconds;
    }

    /** "2-3 min" for compounds, "60-90 s" for isolation work. */
    public function restLabel(): string
    {
        $min = $this->exercise->rest_min_seconds;
        $max = $this->exercise->rest_max_seconds;

        return $max >= 120
            ? rtrim(rtrim(number_format($min / 60, 1), '0'), '.').'-'.rtrim(rtrim(number_format($max / 60, 1), '0'), '.').' min'
            : "{$min}-{$max} s";
    }
}
