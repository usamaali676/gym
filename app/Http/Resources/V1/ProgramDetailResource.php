<?php

namespace App\Http\Resources\V1;

use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use Illuminate\Http\Request;

/**
 * The whole program: sprints / phases (when an admin defined any) and the weekly template, day by day.
 * Expects days.items.exercise (with media and postureRules for the embedded summaries) and phases loaded.
 *
 * @mixin Plan
 */
class ProgramDetailResource extends ProgramResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $card = parent::toArray($request);

        if ($card['locked']) {
            return $card;
        }

        return $card + [
            'rules' => $this->rules,
            'phases' => $this->phases->where('status', 'published')->map(fn ($phase) => [
                'id' => $phase->id,
                'kind' => $phase->kind,
                'name' => $phase->name,
                'summary' => $phase->summary,
                'starts_week' => $phase->starts_week,
                'ends_week' => $phase->ends_week,
                'settings' => (object) ($phase->settings ?? []),
            ])->values()->all(),
            'days' => $this->days->map(fn (PlanDay $day) => $this->day($day))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function day(PlanDay $day): array
    {
        return [
            'day_of_week' => $day->day_of_week,
            'slug' => $day->slug,
            'name' => $day->name,
            'session_type' => $day->session_type->value,
            'label' => $day->label,
            'title' => $day->title(),
            'focus' => $day->focus,
            'is_rest' => (bool) $day->is_rest,
            'description' => $day->description,
            'recovery_note' => $day->recovery_note,
            'estimated_minutes' => $this->estimatedMinutes($day),
            'exercises' => $day->items->map(fn (PlanDayExercise $item) => [
                'id' => $item->id,
                'position' => $item->position,
                'exercise_id' => $item->exercise_id,
                'exercise_slug' => $item->exercise->slug,
                'name' => $item->displayName(),
                'muscle_group' => $item->exercise->muscle_group->value,
                'sets' => $item->sets,
                'reps_min' => $item->reps_min,
                'reps_max' => $item->reps_max,
                'reps_label' => $item->repsLabel(),
                'focus' => $item->focus,
                'notes' => $item->notes,
                'rest_seconds' => $item->restSeconds(),
                'is_timed' => (bool) $item->exercise->is_timed,
                'hold_seconds' => $item->exercise->default_hold_seconds,
            ])->values()->all(),
        ];
    }

    /** Same estimate the website shows (warm-up + sets x (work + rest)). */
    private function estimatedMinutes(PlanDay $day): int
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
}
