<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The workout streak counts consecutive *scheduled training days* that were done.
 * Rest days never break it.
 */
class StreakService
{
    public function __construct(private readonly PlanService $plans) {}

    public function recordWorkout(User $user, CarbonInterface $date): int
    {
        $tz = $user->timezone();
        $date = CarbonImmutable::parse($date->toDateString(), $tz)->startOfDay();
        $last = $user->last_workout_on
            ? CarbonImmutable::parse($user->last_workout_on->toDateString(), $tz)->startOfDay()
            : null;

        if ($last && $date->lte($last)) {
            return $user->workout_streak; // second session the same day, or a back-filled day
        }

        $streak = 1;
        if ($last && ! $this->brokenBetween($user, $last, $date)) {
            $streak = $user->workout_streak + 1;
        }

        $user->forceFill([
            'workout_streak' => $streak,
            'longest_workout_streak' => max($user->longest_workout_streak, $streak),
            'last_workout_on' => $date->toDateString(),
        ])->save();

        return $streak;
    }

    /** The streak as it should be displayed today (0 if a training day has been missed since the last workout). */
    public function current(User $user): int
    {
        if (! $user->last_workout_on) {
            return 0;
        }

        $tz = $user->timezone();
        $last = CarbonImmutable::parse($user->last_workout_on->toDateString(), $tz)->startOfDay();
        $today = $user->localToday();

        // Today is still open, so only days strictly before today can break the streak.
        return $this->brokenBetween($user, $last, $today) ? 0 : $user->workout_streak;
    }

    /** Is any scheduled training day strictly between $from and $to unfinished? */
    private function brokenBetween(User $user, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        for ($d = $from->addDay(); $d->lt($to); $d = $d->addDay()) {
            if ($this->plans->isTrainingDay($user, $d)) {
                return true;
            }
        }

        return false;
    }
}
