<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\RestTime;
use Illuminate\Support\Collection;

/** Builds the JSON the game-mode page boots from. */
class WorkoutPageData
{
    public function __construct(private readonly ProgressiveOverloadService $overload) {}

    /** @return array<string, mixed> */
    public function build(User $user, WorkoutSession $session): array
    {
        $session->load([
            'planDay',
            'exercises.exercise.postureRules',
            'exercises.originalExercise',
            'exercises.planItem.exercise',
            'exercises.sets',
        ]);

        $exercises = $session->exercises->map(fn (SessionExercise $row) => $this->exercise($user, $session, $row))->values();
        $client = $session->autosave_state['client'] ?? null;

        return [
            'session' => [
                'id' => $session->id,
                'status' => $session->status->value,
                'startedAt' => $session->started_at?->getTimestampMs(),
                'title' => $session->planDay?->title() ?? 'Workout',
                'date' => $session->scheduled_for->toDateString(),
                'resume' => $client,
            ],
            'prefs' => [
                'sound' => (bool) $user->profile?->sound_enabled,
                'voice' => (bool) $user->profile?->voice_coach,
            ],
            'urls' => [
                'base' => url('/api/workout-sessions/'.$session->id),
                'state' => route('api.workout.state', $session),
                'sets' => route('api.workout.sets', $session),
                'finish' => route('api.workout.finish', $session),
                'abandon' => route('api.workout.abandon', $session),
                'summary' => route('workout.summary', $session),
                'plan' => $session->planDay ? route('plan.day', ['day' => $session->planDay->day_of_week, 'date' => $session->scheduled_for->toDateString()]) : route('plan.index'),
                'dashboard' => route('dashboard'),
            ],
            'exercises' => $exercises,
            'regions' => config('ironpulse.regions'),
        ];
    }

    /** @return array<string, mixed> */
    public function exercise(User $user, WorkoutSession $session, SessionExercise $row): array
    {
        $exercise = $row->exercise;
        $item = $row->planItem;

        $last = $this->overload->lastPerformance($user, $exercise->id, $session->id);
        $suggestion = $item ? $this->overload->suggest($user, $item, $exercise, $session->id, $last) : null;

        $weight = $row->target_weight_kg ?? $suggestion['weight_kg'] ?? $last['weight'] ?? null;
        $postureRule = $exercise->postureRules->where('is_active', true)->first();

        return [
            'id' => $row->id,
            'exerciseId' => $exercise->id,
            'slug' => $exercise->slug,
            'name' => $item && ! $row->original_exercise_id ? $item->displayName() : $exercise->name,
            'swappedFrom' => $row->originalExercise?->name,
            'canSwap' => $item !== null,
            'demoKey' => $exercise->demo_key ?: $exercise->slug,
            'group' => $exercise->muscle_group->value,
            'groupLabel' => $exercise->muscle_group->label(),
            'mechanic' => $exercise->mechanic->label(),
            'equipment' => $exercise->equipment ?? [],
            'description' => $exercise->description,
            'cues' => $exercise->form_cues ?? [],
            'mistakes' => $exercise->common_mistakes ?? [],
            'breathing' => $exercise->breathing,
            'regions' => $exercise->target_regions ?? ['primary' => [], 'secondary' => []],
            'focus' => $item?->focus,
            'isTimed' => (bool) $exercise->is_timed,
            'holdSeconds' => $exercise->default_hold_seconds,
            'targetSets' => $row->target_sets,
            'repsMin' => $row->target_reps_min,
            'repsMax' => $row->target_reps_max,
            'restSeconds' => RestTime::fixed() ?: ($item?->rest_seconds_override ?: $exercise->default_rest_seconds),
            'restLabel' => RestTime::label() ?? ($exercise->rest_min_seconds >= 120 || $exercise->rest_max_seconds >= 120
                ? round($exercise->rest_min_seconds / 60, 1).'-'.round($exercise->rest_max_seconds / 60, 1).' min'
                : "{$exercise->rest_min_seconds}-{$exercise->rest_max_seconds} s"),
            'weight' => $weight,
            'targetReps' => $suggestion['reps'] ?? $row->target_reps_min,
            'reason' => $suggestion['reason'] ?? 'First time: pick a weight you can lift for '.$row->target_reps_min.'-'.$row->target_reps_max.' reps, leaving 1-2 reps in reserve.',
            'hasHistory' => $last !== null,
            'last' => $last,
            'status' => $row->status,
            'sets' => $row->sets->where('is_warmup', false)->map(fn (SessionSet $s) => $this->set($s))->values()->all(),
            'postureUrl' => $postureRule && route_if('posture.show', ['exercise' => $exercise])
                ? route('posture.show', ['exercise' => $exercise, 'session' => $session->id]) : null,
            'libraryUrl' => route('library.show', $exercise),
        ];
    }

    /** @return array<string, mixed> */
    public function set(SessionSet $set): array
    {
        return [
            'id' => $set->id,
            'number' => $set->set_number,
            'weight' => $set->weight_kg,
            'reps' => $set->reps,
            'duration' => $set->duration_seconds,
            'isPr' => $set->is_pr,
        ];
    }

    /**
     * Alternatives payload shared by the plan swap sheet and the in-session swap.
     *
     * @param  Collection<int, Exercise>  $exercises
     * @return list<array<string, mixed>>
     */
    public function alternatives(Collection $exercises): array
    {
        return $exercises->map(fn ($e) => [
            'id' => $e->id, 'slug' => $e->slug, 'name' => $e->name, 'kind' => $e->kind->value,
            'mechanic' => $e->mechanic->label(), 'difficulty' => $e->difficulty->label(), 'equipment' => $e->equipment,
        ])->values()->all();
    }
}
