<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Answers "what is on the plan, and where is the user in it?".
 * The plan itself is data (plan_days / plan_day_exercises), never hard-coded.
 */
class PlanService
{
    /** @var array<int, Collection<int, PlanDay>> */
    private array $daysCache = [];

    /** @var array<int, array<int, bool>> plan id => [iso weekday => is_rest] (cheap, no exercises) */
    private array $restMapCache = [];

    public function planFor(User $user): ?Plan
    {
        // The relation is cached on the profile instance, so this is one query per request.
        return $user->profile?->plan ?? Plan::default();
    }

    /** @return array<int, bool> iso weekday => is_rest */
    private function restMap(Plan $plan): array
    {
        return $this->restMapCache[$plan->id] ??= $plan->days()->pluck('is_rest', 'day_of_week')
            ->map(fn ($v) => (bool) $v)->all();
    }

    /**
     * All days of a plan keyed by ISO weekday, exercises eager loaded.
     *
     * @return Collection<int, PlanDay>
     */
    public function days(Plan $plan): Collection
    {
        return $this->daysCache[$plan->id] ??= $plan->days()
            ->with(['items.exercise'])
            ->get()
            ->keyBy('day_of_week');
    }

    public function dayFor(User $user, CarbonInterface $date): ?PlanDay
    {
        $plan = $this->planFor($user);

        return $plan ? $this->days($plan)->get($date->dayOfWeekIso) : null;
    }

    /** A training day is a non-rest plan day that the user has not switched off in their profile. */
    public function isTrainingDay(User $user, CarbonInterface $date): bool
    {
        $plan = $this->planFor($user);
        if (! $plan) {
            return false;
        }

        $isRest = $this->restMap($plan)[$date->dayOfWeekIso] ?? true;

        return ! $isRest
            && in_array($date->dayOfWeekIso, $user->profile?->trainingDays() ?? [1, 2, 3, 4, 5], true);
    }

    /** Number of training days in a normal week for this user. */
    public function weeklyTarget(User $user): int
    {
        $plan = $this->planFor($user);
        if (! $plan) {
            return 0;
        }

        $training = $user->profile?->trainingDays() ?? [1, 2, 3, 4, 5];

        return collect($this->restMap($plan))
            ->filter(fn (bool $isRest, int $weekday) => ! $isRest && in_array($weekday, $training, true))
            ->count();
    }

    public function weekNumber(User $user, CarbonInterface $date): int
    {
        $start = $user->profile?->plan_started_on;
        if (! $start) {
            return 1;
        }

        $startDay = CarbonImmutable::parse($start->toDateString(), $user->timezone())->startOfDay();

        return max(1, intdiv(max(0, (int) $startDay->diffInDays($date->startOfDay(), false)), 7) + 1);
    }

    /** Rough session length in minutes: warm-up + sets x (work + rest). */
    public function estimatedMinutes(PlanDay $day): int
    {
        if ($day->is_rest) {
            return 0;
        }

        $seconds = 0;
        foreach ($day->items as $item) {
            $seconds += $item->sets * (config('ironpulse.seconds_per_set') + $item->restSeconds());
        }

        return (int) (round($seconds / 60 / 5) * 5) + config('ironpulse.warmup_minutes');
    }

    /**
     * Seven entries (Mon-Sun) for the week containing $anchor.
     *
     * @return Collection<int, array{date:CarbonImmutable,day:?PlanDay,status:string,session:?WorkoutSession,is_today:bool,is_training:bool}>
     */
    public function weekOverview(User $user, ?CarbonInterface $anchor = null): Collection
    {
        $today = $user->localToday();
        $anchor = $anchor ? CarbonImmutable::instance($anchor) : $today;
        $monday = $anchor->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        $sessions = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('scheduled_for', [$monday->toDateString(), $monday->addDays(6)->toDateString()])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (WorkoutSession $s) => $s->scheduled_for->toDateString());

        $plan = $this->planFor($user);
        $days = $plan ? $this->days($plan) : collect();

        return collect(range(0, 6))->map(function (int $i) use ($monday, $today, $sessions, $days, $user) {
            $date = $monday->addDays($i);
            $day = $days->get($date->dayOfWeekIso);
            $daySessions = $sessions->get($date->toDateString(), collect());
            $session = $daySessions->first(fn ($s) => $s->status === SessionStatus::Completed)
                ?? $daySessions->last();
            $isTraining = $this->isTrainingDay($user, $date);

            $status = match (true) {
                $session?->status === SessionStatus::Completed => 'completed',
                $session?->status === SessionStatus::Skipped => 'skipped',
                $session !== null && $session->isOpen() => 'in_progress',
                ! $isTraining => 'rest',
                $date->lt($today) => 'missed',
                $date->equalTo($today) => 'today',
                default => 'upcoming',
            };

            return [
                'date' => $date,
                'day' => $day,
                'status' => $status,
                'session' => $session,
                'is_today' => $date->equalTo($today),
                'is_training' => $isTraining,
            ];
        });
    }

    /** @return array{done:int,target:int} */
    public function weekProgress(User $user, ?Collection $overview = null): array
    {
        $overview ??= $this->weekOverview($user);

        return [
            'done' => $overview->where('status', 'completed')->where('is_training', true)->count(),
            'target' => $overview->where('is_training', true)->count(),
        ];
    }
}
