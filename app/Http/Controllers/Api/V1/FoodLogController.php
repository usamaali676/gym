<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Meal;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BatchFoodLogRequest;
use App\Http\Requests\CopyFoodLogRequest;
use App\Http\Requests\StoreFoodLogRequest;
use App\Http\Requests\UpdateFoodLogRequest;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\User;
use App\Services\BadgeService;
use App\Services\FoodLogService;
use App\Services\NutritionDayService;
use App\Support\BadgePresenter;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Logging food. The server converts the amount (grams or servings) into grams and macros and stores that result
 * on the entry; the response carries the saved values and the day's refreshed totals.
 */
class FoodLogController extends Controller
{
    public function __construct(
        private readonly FoodLogService $logs,
        private readonly NutritionDayService $days,
        private readonly BadgeService $badges,
    ) {}

    public function store(StoreFoodLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $food = Food::query()->visibleTo($user)->with('servings')->find($request->integer('food_id')) ?? throw ApiException::notFound('Food not found.');
        $date = $request->dateInput('logged_on');

        $log = $this->logs->add($user, $food, $date, Meal::from($request->input('meal')), (float) $request->input('quantity'), $request->input('unit'), $request->input('serving_id'));

        return response()->json(['data' => ['log' => LogPresenter::log($log)] + $this->day($user, $date, true)], 201);
    }

    public function batch(BatchFoodLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->dateInput('logged_on');
        $created = $this->logs->addMany($user, $date, Meal::from($request->input('meal')), $request->validated('items'));

        return response()->json(['data' => ['logs' => $created->map(fn (FoodLog $l) => LogPresenter::log($l))->all()] + $this->day($user, $date, true)], 201);
    }

    public function update(UpdateFoodLogRequest $request, int $log): JsonResponse
    {
        $entry = $this->mine($request, $log);
        $updated = $this->logs->update($entry, (float) $request->input('quantity'), $request->input('unit'), $request->input('serving_id'), $request->filled('meal') ? Meal::from($request->input('meal')) : null);

        return response()->json(['data' => ['log' => LogPresenter::log($updated)] + $this->day($request->user(), $updated->logged_on)]);
    }

    public function destroy(Request $request, int $log): JsonResponse
    {
        $entry = $this->mine($request, $log);
        $date = $entry->logged_on;
        $entry->delete();

        return response()->json(['data' => $this->day($request->user(), $date)]);
    }

    public function copy(CopyFoodLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->dateInput('from');
        $to = $request->dateInput('to');
        if ($from->equalTo($to)) {
            throw new ApiException('validation_failed', 'Pick a different day to copy from.', 422, ['from' => ['Pick a different day to copy from.']]);
        }

        $result = $this->logs->copyDay($user, $from, $to, $request->filled('meal') ? Meal::from($request->input('meal')) : null);

        return response()->json(['data' => $result + $this->day($user, $to)]);
    }

    /** Another member's entry is "not found", not "forbidden". */
    private function mine(Request $request, int $id): FoodLog
    {
        return FoodLog::query()->where('user_id', $request->user()->id)->find($id) ?? throw ApiException::notFound('Entry not found.');
    }

    /** @return array<string, mixed> */
    private function day(User $user, CarbonInterface $date, bool $checkBadges = false): array
    {
        $payload = ['day' => $this->days->state($user->loadMissing('profile'), $date)];
        if ($checkBadges) {
            $payload['badges'] = BadgePresenter::many($this->badges->evaluate($user->refresh(), ['nutrition']));
        }

        return $payload;
    }
}
