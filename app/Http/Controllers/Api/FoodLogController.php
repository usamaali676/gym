<?php

namespace App\Http\Controllers\Api;

use App\Enums\Meal;
use App\Http\Controllers\Controller;
use App\Http\Requests\BatchFoodLogRequest;
use App\Http\Requests\CopyFoodLogRequest;
use App\Http\Requests\StoreFoodLogRequest;
use App\Http\Requests\UpdateFoodLogRequest;
use App\Models\Food;
use App\Models\FoodLog;
use App\Services\FoodLogService;
use App\Services\NutritionDayService;
use App\Support\FoodPresenter;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FoodLogController extends Controller
{
    public function __construct(
        private readonly FoodLogService $logs,
        private readonly NutritionDayService $days,
    ) {}

    public function store(StoreFoodLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $food = Food::query()->visibleTo($user)->with('servings')->findOrFail($request->integer('food_id'));
        $date = $request->dateInput('logged_on');

        $log = $this->logs->add($user, $food, $date, Meal::from($request->input('meal')), (float) $request->input('quantity'), $request->input('unit'), $request->input('serving_id'));

        return response()->json(['log' => FoodPresenter::log($log)] + $this->payload($request->user(), $date), 201);
    }

    public function batch(BatchFoodLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->dateInput('logged_on');

        $created = $this->logs->addMany($user, $date, Meal::from($request->input('meal')), $request->validated('items'));

        return response()->json(['logs' => $created->map(fn (FoodLog $l) => FoodPresenter::log($l))->all()] + $this->payload($user, $date), 201);
    }

    public function update(UpdateFoodLogRequest $request, FoodLog $log): JsonResponse
    {
        Gate::authorize('update', $log);

        $log = $this->logs->update($log, (float) $request->input('quantity'), $request->input('unit'), $request->input('serving_id'), $request->filled('meal') ? Meal::from($request->input('meal')) : null);

        return response()->json(['log' => FoodPresenter::log($log)] + $this->payload($request->user(), $log->logged_on));
    }

    public function destroy(Request $request, FoodLog $log): JsonResponse
    {
        Gate::authorize('delete', $log);
        $date = $log->logged_on;
        $log->delete();

        return response()->json($this->payload($request->user(), $date));
    }

    public function copy(CopyFoodLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->dateInput('from');
        $to = $request->dateInput('to');
        abort_if($from->equalTo($to), 422, 'Pick a different day to copy from.');

        $result = $this->logs->copyDay($user, $from, $to, $request->filled('meal') ? Meal::from($request->input('meal')) : null);

        return response()->json($result + ['logs' => $this->dayLogs($user, $to)] + $this->payload($user, $to));
    }

    /** @return array<string, mixed> */
    private function payload($user, CarbonInterface $date): array
    {
        return ['day' => $this->days->state($user, $date)];
    }

    /** @return list<array<string, mixed>> */
    private function dayLogs($user, CarbonInterface $date): array
    {
        return FoodLog::query()->where('user_id', $user->id)->whereDate('logged_on', $date->toDateString())->orderBy('id')->get()
            ->map(fn (FoodLog $l) => FoodPresenter::log($l))->all();
    }
}
