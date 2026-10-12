<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use App\Models\WaterLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** One call that gathers water, creatine and sleep for a day (and the last two weeks), for the recovery page and its API. */
class HabitService
{
    public function __construct(
        private readonly WaterService $water,
        private readonly DailyLogService $daily,
    ) {}

    /** Everything the browser component needs: today's state and the API addresses. @return array<string, mixed> */
    public function clientConfig(User $user, CarbonInterface $date, bool $history = true): array
    {
        return [
            'state' => $this->state($user, $date, history: $history),
            'urls' => [
                'water' => route('api.water.add'),
                'undoWater' => route('api.water.undo'),
                'creatine' => route('api.creatine.set'),
                'sleep' => route('api.sleep.set'),
                'mobility' => route('api.mobility.set'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function state(User $user, CarbonInterface $date, int $waterXp = 0, bool $history = true): array
    {
        $user->loadMissing('profile');
        $log = $this->daily->forDate($user, $date);
        $creatine = $this->daily->creatineStats($user, $date);

        return [
            'date' => $date->toDateString(),
            'water' => $this->water->state($user, $date, $waterXp),
            'creatine' => [
                'taken' => (bool) $log?->creatine_taken,
                'dose_g' => (float) ($user->profile?->creatine_dose_g ?? 5),
                'current' => $creatine['current'],
                'longest' => $creatine['longest'],
            ],
            'sleep' => [
                'hours' => $log?->sleep_hours,
                'quality' => $log?->sleep_quality,
                'bedtime' => $log?->bedtime ? substr($log->bedtime, 0, 5) : null,
                'wake_time' => $log?->wake_time ? substr($log->wake_time, 0, 5) : null,
                'goal' => (float) ($user->profile?->sleep_goal_hours ?? 7.5),
                'average' => $this->daily->sleepAverage($user, $date, 7),
            ],
            'mobility_minutes' => (int) ($log?->mobility_minutes ?? 0),
            // The dashboard tile does not draw the 14-day strips, so it skips those queries.
            'history' => $history ? $this->history($user, $date, 14) : [],
        ];
    }

    /** @return list<array{date:string, creatine:bool, sleep:?float, water_pct:int, mobility:int}> */
    public function history(User $user, CarbonInterface $end, int $days): array
    {
        $end = CarbonImmutable::parse($end->toDateString());
        $start = $end->subDays($days - 1);

        $logs = DailyLog::query()->where('user_id', $user->id)->whereDate('log_date', '>=', $start->toDateString())->whereDate('log_date', '<=', $end->toDateString())->get()
            ->keyBy(fn (DailyLog $l) => $l->log_date->toDateString());
        $water = WaterLog::query()->where('user_id', $user->id)->whereDate('logged_on', '>=', $start->toDateString())->whereDate('logged_on', '<=', $end->toDateString())
            ->selectRaw('logged_on, SUM(amount_ml) as ml')->groupBy('logged_on')->get()
            ->mapWithKeys(fn ($r) => [CarbonImmutable::parse($r->logged_on)->toDateString() => (int) $r->ml]);

        $out = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $goal = max(1, $this->water->goalMl($user, $d));
            $out[] = [
                'date' => $key,
                'creatine' => (bool) ($logs[$key]->creatine_taken ?? false),
                'sleep' => $logs[$key]->sleep_hours ?? null,
                'water_pct' => (int) min(100, round(($water[$key] ?? 0) / $goal * 100)),
                'mobility' => (int) ($logs[$key]->mobility_minutes ?? 0),
            ];
        }

        return $out;
    }
}
