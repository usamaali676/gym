<?php

namespace App\Services;

use App\Enums\Sex;

/** BMI and tape-measure body-fat estimates. All of these are rough population formulas, labelled as such in the UI. */
class BodyMetricsService
{
    public function bmi(float $weightKg, float $heightCm): float
    {
        $m = $heightCm / 100;

        return $m > 0 ? round($weightKg / ($m * $m), 1) : 0.0;
    }

    /** WHO adult bands. BMI cannot tell muscle from fat, so lifters often read "overweight". */
    public function bmiCategory(float $bmi): string
    {
        return match (true) {
            $bmi < 18.5 => 'Underweight',
            $bmi < 25 => 'Healthy range',
            $bmi < 30 => 'Overweight range',
            default => 'Obese range',
        };
    }

    /**
     * US Navy circumference method (centimetres in, percent out), or null when a needed measurement is missing.
     * With no sex given, the average of the two formulas is used when hips are available.
     */
    public function navyBodyFat(Sex $sex, float $heightCm, ?float $neckCm, ?float $waistCm, ?float $hipsCm): ?float
    {
        if (! $neckCm || ! $waistCm || $heightCm <= 0) {
            return null;
        }

        $male = ($waistCm > $neckCm)
            ? 495 / (1.0324 - 0.19077 * log10($waistCm - $neckCm) + 0.15456 * log10($heightCm)) - 450
            : null;
        $female = ($hipsCm && $waistCm + $hipsCm > $neckCm)
            ? 495 / (1.29579 - 0.35004 * log10($waistCm + $hipsCm - $neckCm) + 0.22100 * log10($heightCm)) - 450
            : null;

        $value = match ($sex) {
            Sex::Male => $male,
            Sex::Female => $female,
            Sex::Unspecified => ($male !== null && $female !== null) ? ($male + $female) / 2 : null,
        };

        return $value === null ? null : round(max(2, min(60, $value)), 1);
    }

    /** @return array{lean_kg:float, fat_kg:float} */
    public function composition(float $weightKg, float $bodyFatPct): array
    {
        $fat = round($weightKg * $bodyFatPct / 100, 1);

        return ['lean_kg' => round($weightKg - $fat, 1), 'fat_kg' => $fat];
    }

    /** Change in weight per week over the period between the first and last point (kg/week), or null with too little data. */
    public function weeklyTrend(array $points): ?float
    {
        if (count($points) < 2) {
            return null;
        }
        $first = $points[0];
        $last = $points[array_key_last($points)];
        $days = max(1, (strtotime($last['date']) - strtotime($first['date'])) / 86400);
        if ($days < 5) {
            return null;
        }

        return round(($last['weight_kg'] - $first['weight_kg']) / $days * 7, 2);
    }
}
