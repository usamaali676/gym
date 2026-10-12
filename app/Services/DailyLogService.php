<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Creatine and sleep: one row per user per day. */
class DailyLogService
{
    public function __construct(private readonly XpService $xp) {}

    public function forDate(User $user, CarbonInterface $date): ?DailyLog
    {
        return DailyLog::query()->where('user_id', $user->id)->whereDate('log_date', $date->toDateString())->first();
    }

    /** Tick or untick today's creatine. XP (once per day) is paid on the first tick only. */
    public function setCreatine(User $user, CarbonInterface $date, bool $taken): DailyLog
    {
        $log = $this->forDate($user, $date) ?? new DailyLog(['user_id' => $user->id, 'log_date' => $date->toDateString()]);
        $log->creatine_taken = $taken;
        $log->creatine_taken_at = $taken ? ($log->creatine_taken_at ?? now()) : null;
        $log->save();

        if ($taken) {
            $this->xp->award($user, 'creatine', (int) config('ironpulse.xp.creatine'), 'Creatine taken', 'creatine_day', (int) $date->format('Ymd'));
        }

        return $log;
    }

    /** @param array{sleep_hours:float|int, sleep_quality?:?int, bedtime?:?string, wake_time?:?string} $data */
    public function logSleep(User $user, CarbonInterface $date, array $data): DailyLog
    {
        $log = $this->forDate($user, $date) ?? new DailyLog(['user_id' => $user->id, 'log_date' => $date->toDateString()]);
        $log->sleep_hours = $data['sleep_hours'];
        $log->sleep_quality = $data['sleep_quality'] ?? null;
        $log->bedtime = $data['bedtime'] ?? null;
        $log->wake_time = $data['wake_time'] ?? null;
        $log->save();

        $goal = (float) ($user->profile?->sleep_goal_hours ?? 7.5);
        if ($log->sleep_hours >= $goal) {
            $this->xp->award($user, 'sleep_goal', (int) config('ironpulse.xp.sleep_goal'), 'Sleep goal met', 'sleep_day', (int) $date->format('Ymd'));
        }

        return $log;
    }

    /**
     * Days in a row with creatine taken. Today not being ticked yet does not break the streak:
     * it counts back from yesterday until the user has had the chance to take it.
     *
     * @return array{current:int, longest:int, taken_days:int}
     */
    public function creatineStats(User $user, CarbonInterface $today): array
    {
        $dates = DailyLog::query()->where('user_id', $user->id)->where('creatine_taken', true)->orderBy('log_date')->pluck('log_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->unique()->values();

        $set = $dates->flip();
        $longest = 0;
        $run = 0;
        $prev = null;
        foreach ($dates as $d) {
            $run = $prev && CarbonImmutable::parse($prev)->addDay()->toDateString() === $d ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $d;
        }

        $cursor = CarbonImmutable::parse($today->toDateString());
        if (! $set->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();
        }
        $current = 0;
        while ($set->has($cursor->toDateString())) {
            $current++;
            $cursor = $cursor->subDay();
        }

        return ['current' => $current, 'longest' => $longest, 'taken_days' => $dates->count()];
    }

    /** Average sleep over the last `$days` nights that were logged. */
    public function sleepAverage(User $user, CarbonInterface $today, int $days = 7): ?float
    {
        $avg = DailyLog::query()->where('user_id', $user->id)->whereNotNull('sleep_hours')
            ->whereDate('log_date', '>', CarbonImmutable::parse($today->toDateString())->subDays($days)->toDateString())->avg('sleep_hours');

        return $avg === null ? null : round((float) $avg, 1);
    }
}
