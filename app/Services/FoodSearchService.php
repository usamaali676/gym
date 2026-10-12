<?php

namespace App\Services;

use App\Enums\DietPreference;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\User;
use Illuminate\Support\Collection;

class FoodSearchService
{
    /**
     * Autocomplete: names that start with the query first, then words that start with it, then anything containing it.
     * Foods the user has logged before get a small boost so "chicken" finds their usual chicken first.
     *
     * @return Collection<int, Food>
     */
    public function search(User $user, string $query, ?DietPreference $diet = null, int $limit = 12): Collection
    {
        $q = Food::normalise($query);
        if (mb_strlen($q) < 1) {
            return collect();
        }
        $like = $this->escape($q);

        $builder = Food::query()
            ->visibleTo($user)
            ->with('servings')
            ->where(function ($w) use ($like, $q) {
                $w->whereRaw("name_search LIKE ? ESCAPE '!'", ["%{$like}%"]);
                // allow "rice basmati" to match "basmati rice, cooked": every word must appear
                $words = array_filter(explode(' ', $q));
                if (count($words) > 1) {
                    $w->orWhere(function ($all) use ($words) {
                        foreach ($words as $word) {
                            $all->whereRaw("name_search LIKE ? ESCAPE '!'", ['%'.$this->escape($word).'%']);
                        }
                    });
                }
            })
            ->when($diet, fn ($b) => $b->whereIn('diet_type', $diet->allowedFoodTypes()))
            ->orderByRaw("CASE WHEN name_search LIKE ? ESCAPE '!' THEN 0 WHEN name_search LIKE ? ESCAPE '!' THEN 1 ELSE 2 END", ["{$like}%", "% {$like}%"])
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END') // the user's own foods first among equals
            ->orderBy('name')
            ->limit($limit * 3);

        $results = $builder->get();

        $usual = $this->usualIds($user, $results->pluck('id')->all());

        return $results
            ->sortBy(fn (Food $f, int $i) => [$this->tier($f, $q), in_array($f->id, $usual, true) ? 0 : 1, $i])
            ->take($limit)
            ->values();
    }

    /**
     * Foods the user logs most often recently, for one-tap logging.
     *
     * @return Collection<int, Food>
     */
    public function recent(User $user, int $limit = 8): Collection
    {
        $ids = FoodLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('food_id')
            ->where('logged_on', '>=', now()->subDays(45)->toDateString())
            ->selectRaw('food_id, MAX(logged_on) as last_on, COUNT(*) as uses')
            ->groupBy('food_id')
            ->orderByDesc('uses')
            ->orderByDesc('last_on')
            ->limit($limit)
            ->pluck('food_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $foods = Food::query()->visibleTo($user)->with('servings')->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $foods->get($id))->filter()->values();
    }

    private function tier(Food $food, string $q): int
    {
        $name = $food->name_search;
        if (str_starts_with($name, $q)) {
            return 0;
        }

        return str_contains($name, " {$q}") ? 1 : 2;
    }

    /** @param  list<int>  $ids @return list<int> */
    private function usualIds(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return FoodLog::query()->where('user_id', $user->id)->whereIn('food_id', $ids)->distinct()->pluck('food_id')->all();
    }

    /** Escape LIKE wildcards using "!" as the escape character (portable across SQLite and MySQL). */
    private function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
