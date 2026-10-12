<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Models\ProgressionTarget;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\UserExerciseSwap;
use App\Models\WorkoutSession;
use App\Support\PlanItemView;

/**
 * "Add 1-2 reps OR add 2.5-5 kg each week." Looks at the user's last session of an exercise
 * (shared across days) and suggests the next target for a plan row's rep range.
 */
class ProgressiveOverloadService
{
    /**
     * The most recent completed session of an exercise.
     *
     * @return array{date:string,sets:list<array{weight:?float,reps:int,duration:?int}>,weight:?float}|null
     */
    public function lastPerformance(User $user, int $exerciseId, ?int $exceptSessionId = null): ?array
    {
        $sessionId = SessionSet::query()
            ->join('workout_sessions', 'workout_sessions.id', '=', 'session_sets.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->where('workout_sessions.status', 'completed')
            ->where('session_sets.exercise_id', $exerciseId)
            ->where('session_sets.is_warmup', false)
            ->when($exceptSessionId, fn ($q) => $q->where('workout_sessions.id', '!=', $exceptSessionId))
            ->orderByDesc('workout_sessions.scheduled_for')
            ->orderByDesc('workout_sessions.id')
            ->value('session_sets.workout_session_id');

        if (! $sessionId) {
            return null;
        }

        $sets = SessionSet::query()
            ->where(['workout_session_id' => $sessionId, 'exercise_id' => $exerciseId, 'is_warmup' => false])
            ->orderBy('set_number')
            ->get();

        $session = WorkoutSession::find($sessionId);

        return [
            'date' => $session->scheduled_for->toDateString(),
            'sets' => $sets->map(fn (SessionSet $s) => [
                'weight' => $s->weight_kg > 0 ? (float) $s->weight_kg : null,
                'reps' => (int) $s->reps,
                'duration' => $s->duration_seconds,
            ])->all(),
            'weight' => $this->workingWeight($sets->pluck('weight_kg')->map(fn ($w) => $w > 0 ? (float) $w : null)->all()),
        ];
    }

    /**
     * Next target for a plan row given the user's last session of the (effective) exercise.
     *
     * @return array{weight_kg:?float,reps:int,reason:string}|null null when there is no history yet
     */
    public function suggest(User $user, PlanDayExercise $item, Exercise $exercise, ?int $exceptSessionId = null, ?array $last = null): ?array
    {
        $last ??= $this->lastPerformance($user, $exercise->id, $exceptSessionId);
        if (! $last || $last['sets'] === []) {
            return null;
        }

        $weight = $last['weight'];
        $atWeight = array_values(array_filter($last['sets'], fn ($s) => $s['weight'] === $weight));
        $reps = array_column($atWeight, 'reps');
        $lowest = min($reps);
        $hitTopEverywhere = count($atWeight) >= $item->sets && $lowest >= $item->reps_max;

        $label = fn (?float $w) => $w === null ? 'bodyweight' : $this->fmt($w).' kg';

        if ($hitTopEverywhere) {
            if ($weight === null) {
                return [
                    'weight_kg' => null,
                    'reps' => $item->reps_max,
                    'reason' => "Hit {$item->reps_max} reps on every set. Add weight (about 2.5 kg) or use less assistance.",
                ];
            }
            $increment = $this->increment($exercise);

            return [
                'weight_kg' => $weight + $increment,
                'reps' => $item->reps_min,
                'reason' => "Hit {$item->reps_max} reps on every set at {$this->fmt($weight)} kg: add {$this->fmt($increment)} kg and start again at {$item->reps_min} reps.",
            ];
        }

        if ($lowest >= $item->reps_min) {
            $target = min($item->reps_max, $lowest + (int) config('ironpulse.overload.rep_step_min'));

            return [
                'weight_kg' => $weight,
                'reps' => $target,
                'reason' => "Beat last time at {$label($weight)}: aim for {$target} reps on every set (add 1-2 reps).",
            ];
        }

        return [
            'weight_kg' => $weight,
            'reps' => $item->reps_min,
            'reason' => "Build up to {$item->reps_min} reps on every set at {$label($weight)} before adding weight.",
        ];
    }

    /**
     * Suggested working weight for every row of a day (keyed by plan_day_exercise id).
     *
     * @return array<int, float|null>
     */
    public function weightTargetsForDay(User $user, PlanDay $day): array
    {
        $day->loadMissing('items.exercise');
        $swaps = UserExerciseSwap::query()->where('user_id', $user->id)
            ->whereIn('plan_day_exercise_id', $day->items->pluck('id'))->with('replacement')->get()->keyBy('plan_day_exercise_id');

        $targets = [];
        foreach ($day->items as $item) {
            $exercise = $swaps->get($item->id)?->replacement ?? $item->exercise;
            $targets[$item->id] = $this->suggest($user, $item, $exercise)['weight_kg'] ?? null;
        }

        return $targets;
    }

    /**
     * Last result and next target for a day's rows, keyed by plan_day_exercise id.
     * Rows with no history are left out, so the plan page only shows what the user has earned.
     *
     * @param  iterable<PlanItemView>  $views
     * @return array<int, array{last:string, next:string, reason:string}>
     */
    public function forViews(User $user, iterable $views): array
    {
        $out = [];
        foreach ($views as $view) {
            $last = $this->lastPerformance($user, $view->exercise->id);
            $next = $last ? $this->suggest($user, $view->item, $view->exercise, null, $last) : null;
            if (! $last || ! $next) {
                continue;
            }

            $setsLabel = implode(', ', array_map(fn ($s) => $s['duration'] && ! $s['reps'] ? $s['duration'].'s' : (string) $s['reps'], $last['sets']));
            $out[$view->item->id] = [
                'last' => ($last['weight'] === null ? 'Bodyweight' : $this->fmt($last['weight']).' kg').' × '.$setsLabel,
                'next' => ($next['weight_kg'] === null ? 'Bodyweight' : $this->fmt($next['weight_kg']).' kg').' × '.$next['reps'],
                'reason' => $next['reason'],
            ];
        }

        return $out;
    }

    /** Recompute and store targets for every plan row the session's exercises feed into. */
    public function refreshAfterSession(User $user, WorkoutSession $session): void
    {
        $planId = $user->profile?->plan_id;
        if (! $planId) {
            return;
        }

        $exerciseIds = $session->sets()->where('is_warmup', false)->pluck('exercise_id')->unique();

        foreach ($exerciseIds as $exerciseId) {
            $exercise = Exercise::find($exerciseId);
            $swappedIn = UserExerciseSwap::where('user_id', $user->id)->where('replacement_exercise_id', $exerciseId)->pluck('plan_day_exercise_id');

            $items = PlanDayExercise::query()
                ->whereHas('day', fn ($q) => $q->where('plan_id', $planId))
                ->where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('exercise_id', $exerciseId)
                        ->whereNotIn('id', UserExerciseSwap::where('user_id', $user->id)->select('plan_day_exercise_id')))
                    ->orWhereIn('id', $swappedIn))
                ->get();

            foreach ($items as $item) {
                $suggestion = $this->suggest($user, $item, $exercise);
                if (! $suggestion) {
                    continue;
                }

                ProgressionTarget::updateOrCreate(
                    ['user_id' => $user->id, 'plan_day_exercise_id' => $item->id],
                    [
                        'exercise_id' => $exerciseId,
                        'target_weight_kg' => $suggestion['weight_kg'],
                        'target_reps' => $suggestion['reps'],
                        'reason' => mb_substr($suggestion['reason'], 0, 160),
                        'based_on_session_id' => $session->id,
                        'computed_at' => now(),
                    ],
                );
            }
        }
    }

    public function increment(Exercise $exercise): float
    {
        $lower = in_array($exercise->muscle_group->value, config('ironpulse.overload.lower_groups'), true);

        return (float) config($lower ? 'ironpulse.overload.lower_increment_kg' : 'ironpulse.overload.upper_increment_kg');
    }

    /** The weight used most often (ties go to the heavier one). Null means bodyweight. */
    private function workingWeight(array $weights): ?float
    {
        $counts = [];
        foreach ($weights as $w) {
            $key = $w === null ? 'bw' : (string) $w;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        uksort($counts, fn ($a, $b) => $counts[$b] <=> $counts[$a] ?: ($b === 'bw' ? -1 : ($a === 'bw' ? 1 : (float) $b <=> (float) $a)));
        $top = array_key_first($counts);

        return $top === 'bw' ? null : (float) $top;
    }

    private function fmt(float $kg): string
    {
        return rtrim(rtrim(number_format($kg, 2, '.', ''), '0'), '.');
    }
}
