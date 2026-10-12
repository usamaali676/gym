<?php

namespace App\Services;

use App\Enums\SessionSource;
use App\Enums\SessionStatus;
use App\Models\Exercise;
use App\Models\PlanDay;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates, runs and closes workout sessions. A session snapshots the day's exercises (with the
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
        private readonly ProgressiveOverloadService $overload,
        private readonly PersonalRecordService $records,
    ) {}

    // ------------------------------------------------------------------ starting

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

    /** The user's unfinished session, if any (only one can be open at a time). */
    public function findOpen(User $user): ?WorkoutSession
    {
        return WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [SessionStatus::InProgress, SessionStatus::Paused])
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

            // A skipped or abandoned day can still be trained: reopen it with a fresh snapshot.
            $session = $existing ?? new WorkoutSession(['user_id' => $user->id]);
            if ($session->exists) {
                $session->exercises()->delete();
                $session->sets()->delete();
            }
            $session->fill([
                'plan_day_id' => $day->id,
                'scheduled_for' => $date->toDateString(),
                'source' => SessionSource::Game,
                'status' => SessionStatus::InProgress,
                'week_number' => $this->plans->weekNumber($user, $date),
                'started_at' => now(),
                'ended_at' => null,
                'active_seconds' => 0,
                'volume_kg' => 0,
                'sets_completed' => 0,
                'autosave_state' => null,
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

        $targets = $this->overload->weightTargetsForDay($user, $day);

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

    // ------------------------------------------------------------------ during

    /**
     * Log (or correct) one set. Idempotent per (exercise, set number): a retry updates, never duplicates.
     *
     * @param  array{session_exercise_id:int,set_number:int,weight_kg?:?float,reps?:?int,duration_seconds?:?int,rpe?:?float,is_warmup?:bool}  $data
     * @return array{set:SessionSet,prs:list<array<string,mixed>>,xp:int,totals:array{sets:int,volume_kg:float,xp_earned:int}}
     */
    public function logSet(User $user, WorkoutSession $session, array $data): array
    {
        $this->assertOpen($session);

        return DB::transaction(function () use ($user, $session, $data) {
            $session = $this->lock($session);
            $this->assertOpen($session);
            $exercise = $session->exercises()->whereKey($data['session_exercise_id'])->firstOrFail();
            $isWarmup = (bool) ($data['is_warmup'] ?? false);

            $set = SessionSet::updateOrCreate(
                ['session_exercise_id' => $exercise->id, 'set_number' => $data['set_number'], 'is_warmup' => $isWarmup],
                [
                    'workout_session_id' => $session->id,
                    'exercise_id' => $exercise->exercise_id,
                    'weight_kg' => $data['weight_kg'] ?? null,
                    'reps' => $data['reps'] ?? null,
                    'duration_seconds' => $data['duration_seconds'] ?? null,
                    'rpe' => $data['rpe'] ?? null,
                    'completed_at' => now(),
                ],
            );

            $prs = $this->records->evaluate($user, $set);
            $set->forceFill(['is_pr' => $prs !== []])->save();

            $earned = 0;
            if (! $isWarmup) {
                // Stable source ids make re-logging or undo/redo of the same set worth XP only once.
                $earned += $this->award($user, $session, 'set_complete', (int) config('ironpulse.xp.set_completed'),
                    "Set {$data['set_number']}", 'set', $this->sourceKey($session, $exercise->exercise_id, $data['set_number']));

                if ($prs !== []) {
                    $earned += $this->award($user, $session, 'personal_record', (int) config('ironpulse.xp.personal_record'),
                        'Personal record', 'pr', $this->sourceKey($session, $exercise->exercise_id));
                    $this->rememberPrs($session, $set, $prs);
                }
            }

            $workingSets = $exercise->sets()->where('is_warmup', false)->count();
            $exercise->update(['status' => $workingSets >= $exercise->target_sets ? 'done' : 'pending']);

            $totals = $this->recalculate($session, $earned);

            return ['set' => $set, 'prs' => $prs, 'xp' => $earned, 'totals' => $totals];
        });
    }

    public function deleteSet(User $user, WorkoutSession $session, SessionSet $set): void
    {
        $this->assertOpen($session);
        abort_unless($set->workout_session_id === $session->id, 404);

        DB::transaction(function () use ($user, $session, $set) {
            $session = $this->lock($session);
            $exerciseId = $set->exercise_id;
            $heldRecord = $set->is_pr || $set->personalRecords()->exists();
            $set->delete();

            if ($heldRecord) {
                $this->records->rebuild($user, $exerciseId);
            }
            $this->recalculate($session, 0);
        });
    }

    /** Replace an exercise inside a running session with a valid same-muscle alternative. */
    public function swapExercise(User $user, WorkoutSession $session, SessionExercise $exercise, int $replacementId): SessionExercise
    {
        $this->assertOpen($session);
        abort_unless($exercise->workout_session_id === $session->id, 404);

        if ($exercise->sets()->exists()) {
            throw ValidationException::withMessages(['exercise_id' => 'You have already logged sets for this exercise.']);
        }
        abort_unless($exercise->planItem, 422, 'This exercise cannot be swapped.');

        $replacement = $this->swaps->alternativesFor($user, $exercise->planItem)->firstWhere('id', $replacementId);
        if (! $replacement) {
            throw ValidationException::withMessages(['exercise_id' => 'That exercise is not a valid alternative for this move.']);
        }

        $exercise->update([
            'original_exercise_id' => $exercise->original_exercise_id ?? $exercise->exercise_id,
            'exercise_id' => $replacement->id,
            'target_weight_kg' => $this->overload->suggest($user, $exercise->planItem, $replacement)['weight_kg'] ?? null,
        ]);

        return $exercise->refresh();
    }

    public function skipExercise(WorkoutSession $session, SessionExercise $exercise): void
    {
        $this->assertOpen($session);
        abort_unless($exercise->workout_session_id === $session->id, 404);

        $exercise->update(['status' => 'skipped']);
    }

    /** Store the browser's resume state (position, timers). The server-owned "result" key is never touched. */
    public function saveClientState(WorkoutSession $session, array $state): void
    {
        DB::transaction(function () use ($session, $state) {
            $session = $this->lock($session);
            $this->assertOpen($session);

            $stored = $session->autosave_state ?? [];
            $stored['client'] = $state;
            $session->update(['autosave_state' => $stored]);
        });
    }

    // ------------------------------------------------------------------ closing

    /**
     * Finish a session: totals, XP, streak, next-week targets. Idempotent.
     *
     * @throws ValidationException when nothing was logged
     */
    public function finish(User $user, WorkoutSession $session, ?int $activeSeconds = null): WorkoutSession
    {
        if ($session->status === SessionStatus::Completed) {
            return $session;
        }
        $this->assertOpen($session);

        if (! $session->sets()->where('is_warmup', false)->exists()) {
            throw ValidationException::withMessages(['session' => 'Log at least one set before finishing, or abandon the workout.']);
        }

        return DB::transaction(function () use ($user, $session, $activeSeconds) {
            $session = $this->lock($session);
            if ($session->status === SessionStatus::Completed) {
                return $session;
            }
            $levelBefore = $user->level;

            foreach ($session->exercises()->withCount(['sets' => fn ($q) => $q->where('is_warmup', false)])->get() as $exercise) {
                $exercise->update(['status' => $exercise->sets_count > 0 ? 'done' : 'skipped']);
            }

            $session->forceFill([
                'status' => SessionStatus::Completed,
                'ended_at' => now(),
                'active_seconds' => $this->clampSeconds($activeSeconds, $session),
            ])->save();

            $this->swaps->consumeTemporary($user, $session->plan_day_id);
            $streak = $this->streaks->recordWorkout($user, $session->scheduled_for);
            $bonus = $this->award($user, $session, 'workout_complete', (int) config('ironpulse.xp.workout_completed'),
                'Workout complete', 'workout_session', $session->id);
            $this->recalculate($session, $bonus);
            $this->overload->refreshAfterSession($user, $session);

            $badges = class_exists(BadgeService::class) ? app(BadgeService::class)->evaluateAfterWorkout($user->refresh(), $session) : [];

            $stored = $session->autosave_state ?? [];
            $stored['result'] = [
                'level_before' => $levelBefore,
                'level_after' => $user->refresh()->level,
                'streak' => $streak,
                'badge_ids' => collect($badges)->pluck('id')->all(),
            ];
            $session->update(['autosave_state' => $stored]);

            return $session->refresh();
        });
    }

    public function abandon(WorkoutSession $session): WorkoutSession
    {
        $this->assertOpen($session);

        $session->forceFill(['status' => SessionStatus::Abandoned, 'ended_at' => now()])->save();

        return $session;
    }

    // ------------------------------------------------------------------ manual days

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

            if (class_exists(BadgeService::class)) {
                app(BadgeService::class)->evaluateAfterWorkout($user->refresh(), $session);
            }

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

    // ------------------------------------------------------------------ helpers

    /** Re-read the session row under a lock so concurrent autosave / set requests cannot overwrite each other's JSON. */
    private function lock(WorkoutSession $session): WorkoutSession
    {
        return WorkoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * XP source ids are derived from the training date, exercise and set number (not row ids), so
     * abandoning and restarting the same day can never pay out twice.
     */
    private function sourceKey(WorkoutSession $session, int $exerciseId, int $extra = 0): int
    {
        return (int) $session->scheduled_for->format('Ymd') * 1_000_000 + $exerciseId * 100 + $extra;
    }

    private function assertOpen(WorkoutSession $session): void
    {
        abort_unless($session->isOpen(), 409, 'This workout is no longer open.');
    }

    /** Award XP and record it in the session's own breakdown. */
    private function award(User $user, WorkoutSession $session, string $type, int $amount, string $description, string $sourceType, int $sourceId): int
    {
        $event = $this->xp->award($user, $type, $amount, $description, $sourceType, $sourceId);
        $amount = $event?->amount ?? 0;

        if ($amount > 0) {
            $stored = $session->autosave_state ?? [];
            $stored['xp'][$type] = ($stored['xp'][$type] ?? 0) + $amount;
            $session->forceFill(['autosave_state' => $stored])->save();
        }

        return $amount;
    }

    /** @return array{sets:int,volume_kg:float,xp_earned:int} */
    private function recalculate(WorkoutSession $session, int $earnedNow): array
    {
        $sets = $session->sets()->where('is_warmup', false)->get(['weight_kg', 'reps']);
        $volume = round($sets->sum(fn ($s) => (float) $s->weight_kg * (int) $s->reps), 2);

        $session->forceFill([
            'sets_completed' => $sets->count(),
            'volume_kg' => $volume,
            'xp_earned' => $session->xp_earned + $earnedNow,
        ])->save();

        return ['sets' => $sets->count(), 'volume_kg' => $volume, 'xp_earned' => $session->xp_earned];
    }

    /** The browser reports active time (it excludes pauses); never trust it beyond the wall clock. */
    private function clampSeconds(?int $reported, WorkoutSession $session): int
    {
        $wallClock = max(0, (int) abs(now()->diffInSeconds($session->started_at ?? now(), true)));

        return max(0, min($reported ?? $wallClock, $wallClock + 60, 6 * 3600));
    }

    /** @param  list<array<string,mixed>>  $prs */
    private function rememberPrs(WorkoutSession $session, SessionSet $set, array $prs): void
    {
        $stored = $session->autosave_state ?? [];
        $stored['prs'][(string) $set->id] = [
            'exercise_id' => $set->exercise_id,
            'weight_kg' => $set->weight_kg,
            'reps' => $set->reps,
            'records' => $prs,
        ];
        $session->update(['autosave_state' => $stored]);
    }

    /** XP earned in this session by type (kept server-side as XP is awarded). */
    public function xpBreakdown(WorkoutSession $session): array
    {
        return $session->autosave_state['xp'] ?? [];
    }

    /** Used by tests and the game page: the exercise a session row is currently set to. */
    public function exerciseOf(SessionExercise $row): Exercise
    {
        return $row->exercise;
    }
}
