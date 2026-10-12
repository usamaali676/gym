<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFoodRequest;
use App\Models\Food;
use App\Services\CustomFoodService;
use App\Services\FoodSearchService;
use App\Support\FoodPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FoodController extends Controller
{
    public function search(Request $request, FoodSearchService $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:80'], 'all' => ['nullable', 'boolean']]);
        $user = $request->user()->load('profile');
        $diet = ! ($data['all'] ?? false) ? $user->profile?->diet_preference : null;

        $foods = $search->search($user, $data['q'], $diet);

        return response()->json([
            'foods' => $foods->map(fn (Food $f) => FoodPresenter::food($f, $user->id))->all(),
            'filtered_by_diet' => $diet?->value,
        ]);
    }

    public function show(Request $request, int $food): JsonResponse
    {
        $user = $request->user();
        $found = Food::query()->visibleTo($user)->with('servings')->findOrFail($food);

        return response()->json(['food' => FoodPresenter::food($found, $user->id)]);
    }

    public function recent(Request $request, FoodSearchService $search): JsonResponse
    {
        $user = $request->user();

        return response()->json(['foods' => $search->recent($user)->map(fn (Food $f) => FoodPresenter::food($f, $user->id))->all()]);
    }

    public function store(StoreFoodRequest $request, CustomFoodService $custom): JsonResponse
    {
        $user = $request->user();
        $food = $custom->create($user, $request->validated());

        return response()->json(['food' => FoodPresenter::food($food, $user->id)], 201);
    }

    public function destroy(Food $food): Response
    {
        Gate::authorize('delete', $food);
        $food->delete();   // log entries keep their name and macro snapshots

        return response()->noContent();
    }
}
