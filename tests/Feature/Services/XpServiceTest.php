<?php

use App\Models\User;
use App\Services\XpService;

test('levels follow the square-root curve', function (int $xp, int $level) {
    expect(app(XpService::class)->levelFor($xp))->toBe($level);
})->with([[0, 1], [99, 1], [100, 2], [399, 2], [400, 3], [900, 4], [10000, 11]]);

test('progress reports how far into the level the user is', function () {
    $p = app(XpService::class)->progress(250);

    expect($p['level'])->toBe(2)->and($p['into'])->toBe(150)->and($p['needed'])->toBe(300)->and($p['pct'])->toBe(0.5)->and($p['next_at'])->toBe(400);
});

test('awarding xp updates the total and level', function () {
    $user = User::factory()->create();
    $xp = app(XpService::class);

    $xp->award($user, 'workout_complete', 120, 'Workout');

    expect($user->refresh()->xp_total)->toBe(120)->and($user->level)->toBe(2);
});

test('the same source cannot be awarded twice', function () {
    $user = User::factory()->create();
    $xp = app(XpService::class);

    expect($xp->award($user, 'water_goal', 10, 'Water', 'water', 20261012))->not->toBeNull()
        ->and($xp->award($user, 'water_goal', 10, 'Water', 'water', 20261012))->toBeNull();

    expect($user->refresh()->xp_total)->toBe(10);
});
