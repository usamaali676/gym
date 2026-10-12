<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Meal;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\ParsesDates;
use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\SavedMeal;
use App\Services\FoodLogService;
use App\Services\NutritionDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Meals a member wants to log again ("usual breakfast"). Macros are recomputed from the foods on every use. */
class SavedMealController extends Controller
{
    use ParsesDates;

    public const MAX_PER_USER = 50;

    public function index(Request $request): JsonResponse
    {
        $meals = SavedMeal::where('user_id', $request->user()->id)->orderBy('name')->get();
        $foods = Food::query()->visibleTo($request->user())->with('servings')->whereIn('id', $meals->flatMap(fn ($m) => array_column($m->items, 'food_id'))->unique())->get()->keyBy('id');
        $logs = app(FoodLogService::class);

        return response()->json(['data' => $meals->map(function (SavedMeal $m) use ($foods, $logs) {
            $items = collect($m->items)->map(function (array $item) use ($foods, $logs) {
                $food = $foods->get($item['food_id']);

                return $food ? ['food_id' => $food->id, 'name' => $food->name, 'grams' => (float) $item['grams']] + $logs->macrosFor($food, (float) $item['grams']) : null;
            })->filter()->values();

            return [
                'id' => $m->id, 'name' => $m->name, 'meal' => $m->meal, 'items' => $items->all(),
                'totals' => ['kcal' => round($items->sum('kcal'), 1), 'protein_g' => round($items->sum('protein_g'), 1), 'carbs_g' => round($items->sum('carbs_g'), 1), 'fat_g' => round($items->sum('fat_g'), 1), 'fibre_g' => round($items->sum('fibre_g'), 1)],
            ];
        })->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'meal' => ['nullable', Rule::in(Meal::values())],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.food_id' => ['required', 'integer'],
            'items.*.grams' => ['required', 'numeric', 'min:1', 'max:5000'],
        ]);
        $user = $request->user();

        if (SavedMeal::where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            throw new ApiException('limit_reached', 'You can keep up to '.self::MAX_PER_USER.' saved meals.', 403, ['max' => self::MAX_PER_USER]);
        }
        $ids = array_unique(array_column($data['items'], 'food_id'));
        if (Food::query()->visibleTo($user)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new ApiException('validation_failed', 'One of those foods is not available.', 422, ['items' => ['One of those foods is not available.']]);
        }

        $meal = SavedMeal::create(['user_id' => $user->id, 'name' => trim($data['name']), 'meal' => $data['meal'] ?? null, 'items' => array_map(fn ($i) => ['food_id' => (int) $i['food_id'], 'grams' => (float) $i['grams']], $data['items'])]);

        return response()->json(['data' => ['id' => $meal->id, 'name' => $meal->name]], 201);
    }

    public function destroy(Request $request, int $meal): JsonResponse
    {
        (SavedMeal::where('user_id', $request->user()->id)->find($meal) ?? throw ApiException::notFound('Saved meal not found.'))->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Log a saved meal on a day. */
    public function apply(Request $request, int $meal, FoodLogService $logs, NutritionDayService $days): JsonResponse
    {
        $data = $request->validate(['logged_on' => ['nullable', 'date_format:Y-m-d'], 'meal' => ['nullable', Rule::in(Meal::values())]]);
        $user = $request->user()->load('profile');
        $saved = SavedMeal::where('user_id', $user->id)->find($meal) ?? throw ApiException::notFound('Saved meal not found.');
        $slot = $data['meal'] ?? $saved->meal ?? throw new ApiException('validation_failed', 'Choose which meal to log it as.', 422, ['meal' => ['Choose which meal to log it as.']]);
        $date = $this->dateParam($request, $user, 'logged_on');

        $created = $logs->addMany($user, $date, Meal::from($slot), $saved->items);

        return response()->json(['data' => ['logs' => $created->map(fn ($l) => LogPresenter::log($l))->all(), 'day' => $days->state($user, $date)]], 201);
    }
}
