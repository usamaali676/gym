<?php

use App\Enums\Sex;
use App\Services\BodyMetricsService;

beforeEach(fn () => $this->svc = new BodyMetricsService);

test('bmi is weight over height squared, rounded to one decimal', function () {
    expect($this->svc->bmi(78, 178))->toBe(24.6)
        ->and($this->svc->bmi(100, 200))->toBe(25.0)
        ->and($this->svc->bmi(70, 0))->toBe(0.0);
});

test('bmi categories use the WHO adult bands', function (float $bmi, string $label) {
    expect($this->svc->bmiCategory($bmi))->toBe($label);
})->with([
    'under' => [18.4, 'Underweight'],
    'low edge' => [18.5, 'Healthy range'],
    'top of healthy' => [24.9, 'Healthy range'],
    'overweight' => [25.0, 'Overweight range'],
    'obese' => [30.0, 'Obese range'],
]);

test('us navy body fat matches published worked examples', function () {
    // Male 180 cm, neck 38, waist 85 -> about 16 %. Female 165 cm, neck 32, waist 72, hips 98 -> about 26-27 %.
    expect($this->svc->navyBodyFat(Sex::Male, 180, 38, 85, null))->toBeBetween(15.5, 16.5)
        ->and($this->svc->navyBodyFat(Sex::Female, 165, 32, 72, 98))->toBeBetween(24.0, 29.0);
});

test('body fat is null when a needed tape measurement is missing', function () {
    expect($this->svc->navyBodyFat(Sex::Male, 180, null, 85, null))->toBeNull()
        ->and($this->svc->navyBodyFat(Sex::Male, 180, 38, null, null))->toBeNull()
        ->and($this->svc->navyBodyFat(Sex::Female, 165, 32, 72, null))->toBeNull()   // women need hips
        ->and($this->svc->navyBodyFat(Sex::Male, 180, 90, 80, null))->toBeNull();     // neck wider than waist makes no sense
});

test('body fat is clamped to a sane range', function () {
    expect($this->svc->navyBodyFat(Sex::Male, 180, 30, 160, null))->toBeLessThanOrEqual(60.0);
});

test('lean and fat mass add back up to body weight', function () {
    $c = $this->svc->composition(80, 20);

    expect($c)->toBe(['lean_kg' => 64.0, 'fat_kg' => 16.0]);
});

test('weekly trend needs at least five days between first and last point', function () {
    expect($this->svc->weeklyTrend([]))->toBeNull()
        ->and($this->svc->weeklyTrend([['date' => '2026-10-01', 'weight_kg' => 80.0]]))->toBeNull()
        ->and($this->svc->weeklyTrend([['date' => '2026-10-01', 'weight_kg' => 80.0], ['date' => '2026-10-03', 'weight_kg' => 79.0]]))->toBeNull()
        ->and($this->svc->weeklyTrend([['date' => '2026-10-01', 'weight_kg' => 80.0], ['date' => '2026-10-15', 'weight_kg' => 79.0]]))->toBe(-0.5);
});
