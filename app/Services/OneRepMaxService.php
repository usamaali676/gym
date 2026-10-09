<?php

namespace App\Services;

/** One-rep-max estimates. Epley is used because it behaves well for the 5-12 rep range this plan trains. */
class OneRepMaxService
{
    /** Beyond this many reps the estimate stops being meaningful, so the rep count is capped. */
    public const MAX_RELIABLE_REPS = 12;

    public function epley(float $weight, int $reps): float
    {
        if ($weight <= 0 || $reps <= 0) {
            return 0.0;
        }

        return $reps === 1 ? $weight : $weight * (1 + min($reps, self::MAX_RELIABLE_REPS) / 30);
    }

    public function brzycki(float $weight, int $reps): float
    {
        if ($weight <= 0 || $reps <= 0) {
            return 0.0;
        }
        $reps = min($reps, self::MAX_RELIABLE_REPS);

        return $reps === 1 ? $weight : $weight * 36 / (37 - $reps);
    }

    /** The estimate used across the app. */
    public function estimate(float $weight, int $reps): float
    {
        return round($this->epley($weight, $reps), 2);
    }

    /** Inverse of Epley: the weight you could lift for a number of reps. */
    public function weightForReps(float $oneRepMax, int $reps): float
    {
        if ($oneRepMax <= 0 || $reps <= 0) {
            return 0.0;
        }

        return $reps === 1 ? $oneRepMax : $oneRepMax / (1 + $reps / 30);
    }

    /**
     * Training percentage table for a 1RM.
     *
     * @return list<array{percent:int,weight:float,reps:int}>
     */
    public function table(float $oneRepMax, float $rounding = 2.5): array
    {
        $rows = [];
        foreach ([100 => 1, 95 => 2, 90 => 4, 85 => 6, 80 => 8, 75 => 10, 70 => 12, 65 => 15, 60 => 20] as $percent => $reps) {
            $rows[] = [
                'percent' => $percent,
                'weight' => round($oneRepMax * $percent / 100 / $rounding) * $rounding,
                'reps' => $reps,
            ];
        }

        return $rows;
    }
}
