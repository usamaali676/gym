<?php

namespace App\Services;

use App\Models\PersonalRecord;
use App\Models\SessionSet;
use App\Models\User;

/**
 * Tracks the best est. 1RM / weight / volume (weighted lifts) or reps (bodyweight) per exercise.
 * A set is a "PR" only if it beats what the user had achieved *before this session*, so a
 * pyramid inside one workout does not look like five separate records, and a first-ever
 * result is a baseline, not a PR.
 */
class PersonalRecordService
{
    /** Record types that make a set count as a PR (volume is tracked but not celebrated). */
    private const PR_TYPES = ['est_1rm', 'max_weight', 'max_reps'];

    public function __construct(private readonly OneRepMaxService $oneRepMax) {}

    /**
     * Update records for a freshly logged working set.
     *
     * @return list<array{type:string,value:float,previous:float}> broken records (empty = no PR)
     */
    public function evaluate(User $user, SessionSet $set): array
    {
        $reps = (int) $set->reps;
        $weight = (float) $set->weight_kg;

        if ($set->is_warmup || $reps < 1) {
            return [];
        }

        $candidates = $weight > 0
            ? [
                'est_1rm' => $this->oneRepMax->estimate($weight, $reps),
                'max_weight' => $weight,
                'best_set_volume' => round($weight * $reps, 2),
            ]
            : ['max_reps' => (float) $reps];

        $broken = [];
        foreach ($candidates as $type => $value) {
            $result = $this->consider($user, $set, $type, $value, $weight > 0 ? $weight : null, $reps);
            if ($result && in_array($type, self::PR_TYPES, true)) {
                $broken[] = $result;
            }
        }

        return $broken;
    }

    /** @return array{type:string,value:float,previous:float}|null */
    private function consider(User $user, SessionSet $set, string $type, float $value, ?float $weight, int $reps): ?array
    {
        $record = PersonalRecord::query()
            ->where(['user_id' => $user->id, 'exercise_id' => $set->exercise_id, 'type' => $type])
            ->first();

        if (! $record) {
            PersonalRecord::create([
                'user_id' => $user->id, 'exercise_id' => $set->exercise_id, 'type' => $type,
                'value' => $value, 'weight_kg' => $weight, 'reps' => $reps,
                'session_set_id' => $set->id, 'achieved_at' => $set->completed_at ?? now(),
            ]);

            return null; // first result for this exercise: a baseline, not a PR
        }

        $heldThisSession = $record->session_set_id
            && SessionSet::whereKey($record->session_set_id)->value('workout_session_id') === $set->workout_session_id;
        $baseline = $heldThisSession ? $record->previous_value : $record->value;

        if ($value > $record->value) {
            $record->update([
                'previous_value' => $heldThisSession ? $record->previous_value : $record->value,
                'value' => $value, 'weight_kg' => $weight, 'reps' => $reps,
                'session_set_id' => $set->id, 'achieved_at' => $set->completed_at ?? now(),
            ]);
        }

        return $baseline !== null && $value > $baseline
            ? ['type' => $type, 'value' => $value, 'previous' => (float) $baseline]
            : null;
    }

    /** Recompute an exercise's records from its remaining sets (used after a set is deleted). */
    public function rebuild(User $user, int $exerciseId): void
    {
        PersonalRecord::where(['user_id' => $user->id, 'exercise_id' => $exerciseId])->delete();

        SessionSet::query()
            ->where('exercise_id', $exerciseId)
            ->where('is_warmup', false)
            ->whereHas('session', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('completed_at')->orderBy('id')
            ->get()
            ->each(fn (SessionSet $set) => $this->evaluate($user, $set));
    }
}
