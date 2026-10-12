<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFoodRequest;
use App\Models\Food;
use App\Services\FoodSearchService;
use App\Support\FoodPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FoodController extends Controller
{
    /** How many foods a user may create (keeps the shared list and the database tidy). */
    private const MAX_CUSTOM_FOODS = 300;

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

    public function store(StoreFoodRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if(Food::where('user_id', $user->id)->count() >= self::MAX_CUSTOM_FOODS, 422, 'You have reached the limit of '.self::MAX_CUSTOM_FOODS.' custom foods.');

        $data = $request->validated();

        $food = DB::transaction(function () use ($user, $data) {
            $food = Food::create([
                'user_id' => $user->id,
                'name' => trim($data['name']),
                'name_search' => Food::normalise($data['name']),
                'brand' => $data['brand'] ?? null,
                'category' => 'custom',
                'diet_type' => $data['diet_type'],
                'kcal_100g' => $data['kcal_100g'],
                'protein_100g' => $data['protein_100g'],
                'carbs_100g' => $data['carbs_100g'],
                'fat_100g' => $data['fat_100g'],
                'fibre_100g' => $data['fibre_100g'] ?? 0,
                'is_public' => false,
                'is_verified' => false,
            ]);
            foreach ($data['servings'] ?? [] as $i => $serving) {
                $food->servings()->create(['label' => $serving['label'], 'grams' => $serving['grams'], 'is_default' => $i === 0]);
            }

            return $food->load('servings');
        });

        return response()->json(['food' => FoodPresenter::food($food, $user->id)], 201);
    }

    public function destroy(Food $food): Response
    {
        Gate::authorize('delete', $food);
        $food->delete();   // log entries keep their name and macro snapshots

        return response()->noContent();
    }
}
