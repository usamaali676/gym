<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\PlanDay;
use Illuminate\Support\Collection;

/**
 * Builds a warm-up or cool-down from the mobility library for the muscles a plan day trains:
 *   warm-up   dynamic drills (swings, circles) for those muscles, with a couple of general ones to fill the time
 *   cool-down static stretches for the same muscles
 *   recovery  on rest days: one static stretch per major muscle group
 * Nothing here is typed per day: change the plan's exercises and the routine follows.
 */
class RoutineService
{
    public const TRANSITION_SECONDS = 10;

    private const GENERAL = ['cat-cow', 'worlds-greatest-stretch', 'inchworm', 'arm-circles', 'hip-circles'];

    /**
     * @return array{type:string, title:string, minutes:int, items:list<array{exercise:Exercise, hold_seconds:int, per_side:bool, seconds:int}>, regions:list<string>}
     */
    public function forDay(PlanDay $day, string $type): array
    {
        $mobility = Exercise::query()->active()->where('kind', 'mobility')->get();

        if ($day->is_rest || $type === 'recovery') {
            return $this->build('recovery', 'Recovery stretch routine', $this->recoveryPicks($mobility), []);
        }

        $day->loadMissing('items.exercise');
        $groups = $day->items->map(fn ($i) => $i->exercise->muscle_group->value)->unique()->values();
        $primary = $day->items->flatMap(fn ($i) => $i->exercise->target_regions['primary'] ?? [])->unique()->values();
        $secondary = $day->items->flatMap(fn ($i) => $i->exercise->target_regions['secondary'] ?? [])->unique()->diff($primary)->values();

        $pool = $mobility->where('mobility_type', $type === 'warmup' ? 'dynamic' : 'static');
        $scored = $pool->map(function (Exercise $e) use ($groups, $primary, $secondary) {
            $regions = $e->target_regions ?? [];
            $score = 2 * count(array_intersect($regions['primary'] ?? [], $primary->all()))
                + count(array_intersect($regions['secondary'] ?? [], $primary->all()))
                + count(array_intersect($regions['primary'] ?? [], $secondary->all()))
                + ($groups->contains($e->muscle_group->value) ? 2 : 0);

            return ['exercise' => $e, 'score' => $score];
        })->sortBy([['score', 'desc'], [fn ($a, $b) => $a['exercise']->id <=> $b['exercise']->id]])->values();

        $limit = 5;
        $picks = $scored->filter(fn ($r) => $r['score'] > 0)->take($limit)->pluck('exercise');

        // Fill up (general drills first, then whatever else fits best) so even an unusual day gets a full routine.
        if ($picks->count() < $limit) {
            $general = $pool->filter(fn (Exercise $e) => in_array($e->slug, self::GENERAL, true) && ! $picks->contains('id', $e->id));
            $picks = $picks->concat($general)->take($limit)->values();
        }
        if ($picks->count() < $limit) {
            $rest = $scored->pluck('exercise')->filter(fn (Exercise $e) => ! $picks->contains('id', $e->id));
            $picks = $picks->concat($rest->take($limit - $picks->count()))->values();
        }

        $subject = $day->focusLabel() ?? ucfirst(strtolower($day->label));   // "Chest" for a push day, "Legs" for leg day
        $title = ($type === 'warmup' ? 'Warm-up' : 'Cool-down').' for '.$subject;

        return $this->build($type, $title, $picks, $primary->concat($secondary)->all());
    }

    /** One static stretch per muscle group, widest coverage first. */
    private function recoveryPicks(Collection $mobility): Collection
    {
        return $mobility->where('mobility_type', 'static')->sortBy('id')->groupBy(fn (Exercise $e) => $e->muscle_group->value)
            ->map(fn (Collection $g) => $g->first())->values()->take(8);
    }

    /** @param  list<string>  $regions */
    private function build(string $type, string $title, Collection $exercises, array $regions): array
    {
        $items = $exercises->map(function (Exercise $e) {
            $hold = (int) ($e->default_hold_seconds ?: 30);
            $sides = $e->per_side ? 2 : 1;

            return ['exercise' => $e, 'hold_seconds' => $hold, 'per_side' => (bool) $e->per_side, 'seconds' => $hold * $sides + self::TRANSITION_SECONDS];
        })->values()->all();

        return [
            'type' => $type,
            'title' => $title,
            'minutes' => (int) max(1, round(array_sum(array_column($items, 'seconds')) / 60)),
            'items' => $items,
            'regions' => $regions,
        ];
    }
}
