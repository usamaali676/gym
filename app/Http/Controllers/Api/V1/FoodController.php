<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFoodRequest;
use App\Http\Resources\V1\FoodResource;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Services\CustomFoodService;
use App\Services\FoodSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FoodController extends Controller
{
    public function search(Request $request, FoodSearchService $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:80'], 'all' => ['nullable', 'boolean']]);
        $user = $request->user()->load('profile');
        $diet = ! ($data['all'] ?? false) ? $user->profile?->diet_preference : null;

        $foods = $search->search($user, $data['q'], $diet)->load('servings');

        return response()->json([
            'data' => FoodResource::collection($foods)->resolve($request),
            'meta' => ['filtered_by_diet' => $diet?->value],
        ]);
    }

    public function recent(Request $request, FoodSearchService $search): AnonymousResourceCollection
    {
        return FoodResource::collection($search->recent($request->user())->load('servings'));
    }

    public function show(Request $request, int $food): FoodResource
    {
        return new FoodResource($this->visible($request, $food));
    }

    public function store(StoreFoodRequest $request, CustomFoodService $custom): JsonResponse
    {
        return (new FoodResource($custom->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    /** Only your own custom foods; logged meals keep their saved name and macros. */
    public function destroy(Request $request, int $food): JsonResponse
    {
        $own = Food::query()->where('user_id', $request->user()->id)->find($food) ?? throw ApiException::notFound('Food not found.');
        $own->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    // ------------------------------------------------------------------ favourites

    public function favorites(Request $request): AnonymousResourceCollection
    {
        $foods = Food::query()->visibleTo($request->user())->with('servings')
            ->whereIn('id', FoodFavorite::where('user_id', $request->user()->id)->pluck('food_id'))->orderBy('name')->get();

        return FoodResource::collection($foods);
    }

    public function favorite(Request $request, int $food): JsonResponse
    {
        $found = $this->visible($request, $food);
        FoodFavorite::firstOrCreate(['user_id' => $request->user()->id, 'food_id' => $found->id]);

        return response()->json(['data' => ['food_id' => $found->id, 'favorite' => true]]);
    }

    public function unfavorite(Request $request, int $food): JsonResponse
    {
        FoodFavorite::where('user_id', $request->user()->id)->where('food_id', $food)->delete();

        return response()->json(['data' => ['food_id' => $food, 'favorite' => false]]);
    }

    private function visible(Request $request, int $id): Food
    {
        return Food::query()->visibleTo($request->user())->with('servings')->find($id) ?? throw ApiException::notFound('Food not found.');
    }
}
