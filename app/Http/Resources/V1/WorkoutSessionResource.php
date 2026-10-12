<?php

namespace App\Http\Resources\V1;

use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\WorkoutSession;
use App\Services\WorkoutPageData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A running or finished workout with everything the session screen needs: exercises (with the server's suggested
 * weight and reps), logged sets and the stored resume state. Timers are NOT sent as running clocks: the app derives
 * them from `started_at`, `server_time` and the timestamps it saved in `client_state`.
 *
 * Expects planDay, exercises.exercise.postureRules, exercises.originalExercise, exercises.planItem.exercise and
 * exercises.sets to be loaded.
 *
 * @mixin WorkoutSession
 */
class WorkoutSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $data = app(WorkoutPageData::class);

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'title' => $this->planDay?->title() ?? 'Workout',
            'plan_day' => $this->planDay ? ['id' => $this->planDay->id, 'weekday' => $this->planDay->day_of_week, 'name' => $this->planDay->name] : null,
            'scheduled_for' => $this->scheduled_for->toDateString(),
            'week_number' => $this->week_number,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'active_seconds' => (int) $this->active_seconds,
            'volume_kg' => $this->volume_kg,
            'sets_completed' => (int) $this->sets_completed,
            'xp_earned' => (int) $this->xp_earned,
            'client_state' => $this->autosave_state['client'] ?? null,
            'server_time' => now()->toIso8601String(),
            'preferences' => ['sound' => (bool) $user->profile?->sound_enabled, 'voice' => (bool) $user->profile?->voice_coach],
            'exercises' => $this->exercises->map(fn (SessionExercise $row) => $this->exercise($data->exercise($user, $this->resource, $row), $row))->values()->all(),
        ];
    }

    /**
     * Rename WorkoutPageData's browser-oriented keys (camelCase, page URLs) to the API's snake_case contract.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function exercise(array $e, SessionExercise $row): array
    {
        return [
            'id' => $e['id'],
            'exercise_id' => $e['exerciseId'],
            'slug' => $e['slug'],
            'name' => $e['name'],
            'swapped_from' => $e['swappedFrom'],
            'can_swap' => $e['canSwap'],
            'position' => $row->position,
            'status' => $e['status'],
            'muscle_group' => $e['group'],
            'muscle_group_label' => $e['groupLabel'],
            'mechanic' => $e['mechanic'],
            'equipment' => $e['equipment'],
            'description' => $e['description'],
            'form_cues' => $e['cues'],
            'common_mistakes' => $e['mistakes'],
            'breathing' => $e['breathing'],
            'target_regions' => $e['regions'],
            'focus' => $e['focus'],
            'is_timed' => $e['isTimed'],
            'hold_seconds' => $e['holdSeconds'],
            'target_sets' => $e['targetSets'],
            'reps_min' => $e['repsMin'],
            'reps_max' => $e['repsMax'],
            'rest_seconds' => $e['restSeconds'],
            'rest_label' => $e['restLabel'],
            'suggested' => ['weight_kg' => $e['weight'], 'reps' => $e['targetReps'], 'reason' => $e['reason']],
            'has_history' => $e['hasHistory'],
            'last' => $e['last'],
            'posture_supported' => $row->exercise->postureRules->where('is_active', true)->isNotEmpty(),
            'sets' => $row->sets->where('is_warmup', false)->sortBy('set_number')->map(fn (SessionSet $s) => self::set($s))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function set(SessionSet $set): array
    {
        return [
            'id' => $set->id,
            'number' => $set->set_number,
            'weight_kg' => $set->weight_kg !== null ? (float) $set->weight_kg : null,
            'reps' => $set->reps,
            'duration_seconds' => $set->duration_seconds,
            'rpe' => $set->rpe !== null ? (float) $set->rpe : null,
            'is_warmup' => (bool) $set->is_warmup,
            'is_pr' => (bool) $set->is_pr,
            'completed_at' => $set->completed_at?->toIso8601String(),
        ];
    }
}
