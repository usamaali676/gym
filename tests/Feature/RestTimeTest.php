<?php

use App\Models\PlanDay;
use App\Models\User;
use App\Support\PlanItemView;
use App\Support\RestTime;

beforeEach(fn () => seedPlan());

test('every exercise rests 20 seconds by default', function () {
    expect(RestTime::fixed())->toBe(20)->and(RestTime::label())->toBe('20 s');

    $items = PlanDay::where('is_rest', false)->with('items.exercise')->first()->items;

    expect($items->pluck('exercise.default_rest_seconds')->unique()->count())->toBeGreaterThan(1) // the stored values still differ
        ->and($items->map(fn ($item) => $item->restSeconds())->unique()->all())->toBe([20])
        ->and($items->map(fn ($item) => (new PlanItemView($item))->restLabel())->unique()->all())->toBe(['20 s']);
});

test('setting the rest to 0 brings back each exercise\'s own rest', function () {
    config(['ironpulse.rest_seconds' => 0]);

    $items = PlanDay::where('is_rest', false)->with('items.exercise')->first()->items;

    expect(RestTime::fixed())->toBe(0)->and(RestTime::label())->toBeNull()
        ->and($items->map(fn ($item) => $item->restSeconds())->unique()->count())->toBeGreaterThan(1);
});
