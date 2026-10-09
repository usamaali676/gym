<?php

namespace App\Services;

use App\Enums\SessionSource;
use App\Enums\SessionStatus;
use App\Models\PlanDay;
use App\Models\SessionExercise;
use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates and closes workout sessions. A session snapshots the day's exercises (with the
 * user's swaps and targets) so history stays accurate even if the plan is edited later.
 */
class WorkoutSessionService
{
    /** How many days back a day can still be marked complete or skipped. */
    public const BACKFILL_DAYS = 14;

    public function __construct(
        private readonly PlanService $plans,
        private readonly SwapService $swaps,
        private readonly XpService $xp,
        private readonly StreakService $streaks,
    ) {}

    /** @throws ValidationException when the date does not suit this plan day */
    public function assertDateAllowed(User $user, PlanDay $day, CarbonInterface $date): void
    {
        $today = $user->localToday();
        $date = CarbonImmutable::instance($date)->startOfDay();

        if ($date->dayOfWeekIso !== $day->day_of_week) {
            throw ValidationException::withMessages(['date' => "That date is not a {$day->name}."]);
        }
        if ($date->gt($today)) {
            throw ValidationException::withMessages(['date' => 'You cannot log a workout for a future day.']);
        }
        if ($date->lt($today->subDays(self::BACKFILL_DAYS))) {
            throw ValidationException::withMessages(['date' => 'That day is too far in the past to change.']);
        }
        if ($day->is_rest) {
            throw ValidationException::withMessages(['date' => 'Rest days have no workout to log.']);
        }
    }

    public function sessionOn(User $user, PlanDay $day, CarbonInterface $date): ?WorkoutSession
    {
        return WorkoutSession::query()
            ->where('user_id', $user->id)
            ->where('plan_day_id', $day->id)
            ->whereDate('scheduled_for', $date->toDateString())
            ->latest('id')
            ->first();
    }

    /** Start (or resume) the session for a plan day on a date. */
    public function start(User $user, PlanDay $day, CarbonInterface $date, ?string $clientUuid = null): WorkoutSession
    {
        $this->assertDateAllowed($user, $day, $date);

        return DB::transaction(function () use ($user, $day, $date, $clientUuid) {
            $existing = $this->sessionOn($user, $day, $date);
            if ($existing && ($existing->isOpen() || $existing->status === SessionStatus::Completed)) {
                return $existing;
            }

            // A skipped day can still be trained: reopen it.
            $session = $existing ?? new WorkoutSession(['user_id' => $user->id]);
            $session->fill([
                'plan_day_id' => $day->id,
                'scheduled_for' => $date->toDateString(),
                'source' => SessionSource::Game,
                'status' => SessionStatus::InProgress,
                'week_number' => $this->plans->weekNumber($user, $date),
                'started_at' => now(),
                'ended_at' => null,
            ]);
            $session->client_uuid ??= $clientUuid ?: (string) Str::uuid();
            $session->save();

            $this->snapshot($user, $session, $day);

            return $session;
        });
    }

    /** Copy the day's (swapped) exercises into the session. Idempotent. */
    public function snapshot(User $user, WorkoutSession $session, PlanDay $day): void
    {
        if ($session->exercises()->exists()) {
            return;
        }

        $targets = $this->targetsFor($user, $day);

        foreach ($this->plans->effectiveItems($user, $day) as $view) {
            SessionExercise::create([
                'workout_session_id' => $session->id,
                'exercise_id' => $view->exercise->id,
                'original_exercise_id' => $view->swapped() ? $view->original()->id : null,
                'plan_day_exercise_id' => $view->item->id,
                'position' => $view->item->position,
                'target_sets' => $view->item->sets,
                'target_reps_min' => $view->item->reps_min,
                'target_reps_max' => $view->item->reps_max,
                'target_weight_kg' => $targets[$view->item->id] ?? null,
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Suggested weights by plan row id. Filled in by the progressive overload service.
     *
     * @return array<int, float|null>
     */
    protected function targetsFor(User $user, PlanDay $day): array
    {
        return class_exists(ProgressiveOverloadService::class)
            ? app(ProgressiveOverloadService::class)->weightTargetsForDay($user, $day)
            : [];
    }

    /** Mark a day as done without running game mode (idempotent per date). */
    public function completeManually(User $user, PlanDay $day, CarbonInterface $date): WorkoutSession
    {
        $this->assertDateAllowed($user, $day, $date);

        return DB::transaction(function () use ($user, $day, $date) {
            $session = $this->sessionOn($user, $day, $date);

            if ($session?->status === SessionStatus::Completed) {
                return $session;
            }

            $session ??= new WorkoutSession(['user_id' => $user->id]);
            $session->fill([
                'plan_day_id' => $day->id,
                'scheduled_for' => $date->toDateString(),
                'source' => $session->exists ? $session->source : SessionSource::Manual,
                'week_number' => $this->plans->weekNumber($user, $date),
                'started_at' => $session->started_at ?? now(),
            ]);
            $session->client_uuid ??= (string) Str::uuid();
            $session->status = SessionStatus::Completed;
            $session->ended_at = now();
            $session->save();

            $this->snapshot($user, $session, $day);
            $this->swaps->consumeTemporary($user, $day->id);
            $this->streaks->recordWorkout($user, $date);

            $earned = $this->xp->award(
                $user, 'workout_complete', (int) config('ironpulse.xp.workout_completed'),
                "Completed {$day->label}, {$day->focus}", 'workout_session', $session->id,
            );
            $session->forceFill(['xp_earned' => $session->xp_earned + ($earned?->amount ?? 0)])->save();

            return $session;
        });
    }

    public function skip(User $user, PlanDay $day, CarbonInterface $date): WorkoutSession
    {
        $this->assertDateAllowed($user, $day, $date);

        $session = $this->sessionOn($user, $day, $date);

        if ($session?->status === SessionStatus::Completed) {
            throw ValidationException::withMessages(['date' => 'This day is already completed.']);
        }

        $session ??= new WorkoutSession(['user_id' => $user->id, 'client_uuid' => (string) Str::uuid()]);
        $session->fill([
            'plan_day_id' => $day->id,
            'scheduled_for' => $date->toDateString(),
            'source' => SessionSource::Manual,
            'status' => SessionStatus::Skipped,
            'week_number' => $this->plans->weekNumber($user, $date),
            'ended_at' => now(),
        ])->save();

        return $session;
    }

    /** Undo a skip (only skipped sessions can be removed this way). */
    public function unskip(User $user, PlanDay $day, CarbonInterface $date): void
    {
        $session = $this->sessionOn($user, $day, $date);

        if ($session?->status === SessionStatus::Skipped) {
            $session->delete();
        }
    }
}
