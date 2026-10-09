<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\PlanDayExercise;
use App\Models\User;
use App\Models\UserExerciseSwap;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Swapping a plan exercise for an alternative that trains the same muscle group. */
class SwapService
{
    private const LEVELS = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];

    /**
     * Same-muscle-group exercises the user could do instead, filtered by their equipment.
     *
     * @return Collection<int, Exercise>
     */
    public function alternativesFor(User $user, PlanDayExercise $item): Collection
    {
        $item->loadMissing('exercise');
        $current = $item->exercise;
        $owned = $this->ownedEquipment($user);
        $level = self::LEVELS[$user->profile?->fitness_level?->value ?? 'intermediate'];

        return Exercise::query()
            ->active()
            ->whereIn('kind', ['plan', 'alternative'])
            ->where('muscle_group', $current->muscle_group)
            ->where('id', '!=', $current->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Exercise $e) => $this->usableWith($e, $owned))
            ->sortBy(fn (Exercise $e) => [
                $e->mechanic === $current->mechanic ? 0 : 1,
                self::LEVELS[$e->difficulty->value] <= $level ? 0 : 1,
                $e->name,
            ])
            ->values();
    }

    public function swap(User $user, PlanDayExercise $item, int $replacementId, bool $permanent): UserExerciseSwap
    {
        // Only exercises we would offer can be swapped in (blocks tampering with arbitrary ids).
        $replacement = $this->alternativesFor($user, $item)->firstWhere('id', $replacementId);

        if (! $replacement) {
            throw ValidationException::withMessages(['exercise_id' => 'That exercise is not a valid alternative for this move.']);
        }

        return UserExerciseSwap::updateOrCreate(
            ['user_id' => $user->id, 'plan_day_exercise_id' => $item->id],
            ['replacement_exercise_id' => $replacement->id, 'is_permanent' => $permanent],
        );
    }

    public function clear(User $user, PlanDayExercise $item): void
    {
        UserExerciseSwap::where(['user_id' => $user->id, 'plan_day_exercise_id' => $item->id])->delete();
    }

    /** One-off swaps only last until the day has been trained once. */
    public function consumeTemporary(User $user, int $planDayId): void
    {
        UserExerciseSwap::query()
            ->where('user_id', $user->id)
            ->where('is_permanent', false)
            ->whereIn('plan_day_exercise_id', PlanDayExercise::where('plan_day_id', $planDayId)->select('id'))
            ->delete();
    }

    /** @return list<string> profile equipment plus bodyweight (always available) */
    public function ownedEquipment(User $user): array
    {
        return array_values(array_unique([...($user->profile?->equipment ?? []), 'bodyweight']));
    }

    /** @param  list<string>  $owned */
    public function usableWith(Exercise $exercise, array $owned): bool
    {
        $map = config('ironpulse.equipment_map');

        foreach ($exercise->equipment ?? [] as $tag) {
            $needed = $map[$tag] ?? $tag;
            if (! in_array($needed, $owned, true)) {
                return false;
            }
        }

        return true;
    }
}
