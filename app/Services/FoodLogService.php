<?php

namespace App\Services;

use App\Enums\Meal;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\FoodServing;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes food log entries. Every entry stores a snapshot of the food's name and the macros for the
 * amount eaten, so history stays correct when a custom food is edited or deleted later.
 */
class FoodLogService
{
    public const MAX_GRAMS = 5000;

    /**
     * Macros for an amount of a food.
     *
     * @return array{kcal:float,protein_g:float,carbs_g:float,fat_g:float,fibre_g:float}
     */
    public function macrosFor(Food $food, float $grams): array
    {
        $f = $grams / 100;

        return [
            'kcal' => round($food->kcal_100g * $f, 1),
            'protein_g' => round($food->protein_100g * $f, 1),
            'carbs_g' => round($food->carbs_100g * $f, 1),
            'fat_g' => round($food->fat_100g * $f, 1),
            'fibre_g' => round($food->fibre_100g * $f, 1),
        ];
    }

    /**
     * Resolve "2 servings" or "150 g" to grams for a food.
     *
     * @return array{grams: float, serving: ?FoodServing}
     */
    public function resolveAmount(Food $food, float $quantity, string $unit, ?int $servingId): array
    {
        if ($unit === 'serving') {
            $serving = $food->servings->firstWhere('id', $servingId);
            if (! $serving) {
                throw ValidationException::withMessages(['serving_id' => 'Pick one of this food\'s servings.']);
            }
            $grams = $quantity * $serving->grams;
        } else {
            $serving = null;
            $grams = $quantity;
        }

        if ($grams < 1 || $grams > self::MAX_GRAMS) {
            throw ValidationException::withMessages(['quantity' => 'That amount is outside 1 g to '.number_format(self::MAX_GRAMS).' g.']);
        }

        return ['grams' => round($grams, 1), 'serving' => $serving];
    }

    public function add(User $user, Food $food, CarbonInterface $date, Meal $meal, float $quantity, string $unit = 'grams', ?int $servingId = null): FoodLog
    {
        $food->loadMissing('servings');
        ['grams' => $grams, 'serving' => $serving] = $this->resolveAmount($food, $quantity, $unit, $servingId);

        return FoodLog::create([
            'user_id' => $user->id,
            'food_id' => $food->id,
            'logged_on' => $date->toDateString(),
            'meal' => $meal,
            'name_snapshot' => $food->name,
            'quantity' => $quantity,
            'unit' => $unit,
            'serving_label' => $serving?->label,
            'grams' => $grams,
        ] + $this->macrosFor($food, $grams));
    }

    /**
     * Log several foods at once (used for "log this meal").
     *
     * @param  list<array{food_id:int, grams:float|int}>  $items
     * @return Collection<int, FoodLog>
     */
    public function addMany(User $user, CarbonInterface $date, Meal $meal, array $items): Collection
    {
        $foods = Food::query()->visibleTo($user)->with('servings')->whereIn('id', array_column($items, 'food_id'))->get()->keyBy('id');

        return DB::transaction(fn () => collect($items)->map(function (array $item) use ($user, $date, $meal, $foods) {
            $food = $foods->get($item['food_id']) ?? throw ValidationException::withMessages(['items' => 'One of those foods is not available.']);

            return $this->add($user, $food, $date, $meal, (float) $item['grams']);
        }));
    }

    public function update(FoodLog $log, float $quantity, string $unit, ?int $servingId, ?Meal $meal = null): FoodLog
    {
        $food = $log->food_id ? Food::query()->with('servings')->find($log->food_id) : null;

        if ($food) {
            ['grams' => $grams, 'serving' => $serving] = $this->resolveAmount($food, $quantity, $unit, $servingId);
            $log->fill([
                'quantity' => $quantity, 'unit' => $unit, 'serving_label' => $serving?->label, 'grams' => $grams,
            ] + $this->macrosFor($food, $grams));
        } else {
            // The food was deleted: scale the stored snapshot instead.
            $grams = $unit === 'grams' ? $quantity : $log->grams * ($quantity / max(0.01, $log->quantity));
            if ($grams < 1 || $grams > self::MAX_GRAMS) {
                throw ValidationException::withMessages(['quantity' => 'That amount is outside 1 g to '.number_format(self::MAX_GRAMS).' g.']);
            }
            $factor = $grams / max(0.1, $log->grams);
            $log->fill([
                'quantity' => $quantity, 'grams' => round($grams, 1),
                'kcal' => round($log->kcal * $factor, 1), 'protein_g' => round($log->protein_g * $factor, 1),
                'carbs_g' => round($log->carbs_g * $factor, 1), 'fat_g' => round($log->fat_g * $factor, 1), 'fibre_g' => round($log->fibre_g * $factor, 1),
            ]);
        }

        if ($meal) {
            $log->meal = $meal;
        }
        $log->save();

        return $log;
    }

    /**
     * Copy a day's entries to another day. Meals that already have entries on the target day are left alone,
     * so pressing the button twice cannot double your day.
     *
     * @return array{copied:int, skipped:list<string>}
     */
    public function copyDay(User $user, CarbonInterface $from, CarbonInterface $to, ?Meal $only = null): array
    {
        $source = FoodLog::query()->where('user_id', $user->id)->whereDate('logged_on', $from->toDateString())
            ->when($only, fn ($q) => $q->where('meal', $only->value))
            ->orderBy('id')->get();

        $occupied = FoodLog::query()->where('user_id', $user->id)->whereDate('logged_on', $to->toDateString())
            ->pluck('meal')->map(fn ($m) => $m instanceof Meal ? $m->value : $m)->unique()->all();

        $skipped = [];
        $copied = 0;

        DB::transaction(function () use ($source, $to, &$copied, &$skipped, $occupied) {
            foreach ($source as $row) {
                if (in_array($row->meal->value, $occupied, true)) {
                    $skipped[$row->meal->value] = $row->meal->label();

                    continue;
                }
                FoodLog::create([
                    'user_id' => $row->user_id, 'food_id' => $row->food_id, 'logged_on' => $to->toDateString(), 'meal' => $row->meal,
                    'name_snapshot' => $row->name_snapshot, 'quantity' => $row->quantity, 'unit' => $row->unit, 'serving_label' => $row->serving_label,
                    'grams' => $row->grams, 'kcal' => $row->kcal, 'protein_g' => $row->protein_g, 'carbs_g' => $row->carbs_g,
                    'fat_g' => $row->fat_g, 'fibre_g' => $row->fibre_g,
                ]);
                $copied++;
            }
        });

        return ['copied' => $copied, 'skipped' => array_values($skipped)];
    }

    /**
     * A day's entries grouped by meal, in meal order.
     *
     * @return array<string, array{label:string, kcal:float, protein_g:float, entries: Collection<int, FoodLog>}>
     */
    public function dayByMeal(User $user, CarbonInterface $date): array
    {
        $logs = FoodLog::query()->where('user_id', $user->id)->whereDate('logged_on', $date->toDateString())->orderBy('id')->get();

        $out = [];
        foreach (Meal::cases() as $meal) {
            $entries = $logs->filter(fn (FoodLog $l) => $l->meal === $meal)->values();
            $out[$meal->value] = [
                'label' => $meal->label(),
                'kcal' => round($entries->sum('kcal'), 1),
                'protein_g' => round($entries->sum('protein_g'), 1),
                'entries' => $entries,
            ];
        }

        return $out;
    }
}
