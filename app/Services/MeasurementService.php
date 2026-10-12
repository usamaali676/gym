<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\Measurement;
use App\Models\User;

/** Saves a day's body measurements. Shared by the website and the mobile API. */
class MeasurementService
{
    public function __construct(private readonly BadgeService $badges) {}

    /**
     * One row per member per day: fields left blank keep what was already stored for that day, and the newest
     * weight becomes the member's current weight (so the diet page offers to recalculate).
     *
     * @param  array<string, mixed>  $data
     * @return array{measurement: Measurement, badges: list<Badge>}
     */
    public function save(User $user, string $measuredOn, array $data): array
    {
        $user->loadMissing('profile');

        $existing = Measurement::where('user_id', $user->id)->whereDate('measured_on', $measuredOn)->first();
        $row = $existing ?? new Measurement(['user_id' => $user->id, 'measured_on' => $measuredOn]);
        $row->fill(array_filter($data, fn ($v) => $v !== null));
        $row->save();

        $latest = Measurement::where('user_id', $user->id)->whereNotNull('weight_kg')->orderByDesc('measured_on')->first();
        if ($latest && $user->profile && abs($user->profile->weight_kg - $latest->weight_kg) > 0.04) {
            $user->profile->update(['weight_kg' => $latest->weight_kg]);
        }

        return ['measurement' => $row, 'badges' => $this->badges->evaluate($user->refresh(), ['body'])];
    }
}
