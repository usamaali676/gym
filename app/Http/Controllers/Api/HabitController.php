<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyLog;
use App\Services\BadgeService;
use App\Services\DailyLogService;
use App\Services\HabitService;
use App\Services\WaterService;
use App\Support\BadgePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Water, creatine, sleep and mobility minutes for a day (today by default, up to a week back). */
class HabitController extends Controller
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly WaterService $water,
        private readonly DailyLogService $daily,
        private readonly BadgeService $badges,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->habits->state($request->user(), $this->date($request)));
    }

    public function addWater(Request $request): JsonResponse
    {
        $data = $request->validate(['amount_ml' => ['required', 'integer', 'min:10', 'max:'.WaterService::MAX_SINGLE_ML]]);
        $date = $this->date($request);

        $state = $this->water->add($request->user(), $date, (int) $data['amount_ml']);

        return $this->respond($request, $date, $state['xp']);
    }

    public function undoWater(Request $request): JsonResponse
    {
        $date = $this->date($request);
        $this->water->undoLast($request->user(), $date);

        return $this->respond($request, $date);
    }

    public function creatine(Request $request): JsonResponse
    {
        $data = $request->validate(['taken' => ['required', 'boolean']]);
        $date = $this->date($request);
        $this->daily->setCreatine($request->user(), $date, (bool) $data['taken']);

        return $this->respond($request, $date);
    }

    public function sleep(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sleep_hours' => ['required', 'numeric', 'min:0', 'max:16'],
            'sleep_quality' => ['nullable', 'integer', 'between:1,5'],
            'bedtime' => ['nullable', 'date_format:H:i'],
            'wake_time' => ['nullable', 'date_format:H:i'],
        ]);
        $date = $this->date($request);
        $this->daily->logSleep($request->user(), $date, $data);

        return $this->respond($request, $date);
    }

    public function mobility(Request $request): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'between:0,240']]);
        $date = $this->date($request);
        $log = $this->daily->forDate($request->user(), $date) ?? new DailyLog(['user_id' => $request->user()->id, 'log_date' => $date->toDateString()]);
        $log->mobility_minutes = (int) $data['minutes'];
        $log->save();

        return $this->respond($request, $date);
    }

    private function respond(Request $request, CarbonImmutable $date, int $waterXp = 0): JsonResponse
    {
        $user = $request->user();
        $awarded = $this->badges->evaluate($user->refresh(), ['habits', 'level']);

        return response()->json($this->habits->state($user, $date, $waterXp) + ['badges' => BadgePresenter::many($awarded)]);
    }

    /** The day being edited: today in the user's time zone, or up to 7 days back. */
    private function date(Request $request): CarbonImmutable
    {
        $user = $request->user();
        $today = $user->localToday();
        $value = $request->input('date', $request->query('date'));
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $today;
        }
        $date = CarbonImmutable::createFromFormat('Y-m-d', $value, $user->timezone())->startOfDay();
        abort_if($date->gt($today) || $date->lt($today->subDays(7)), 422, 'You can only edit the last 7 days.');

        return $date;
    }
}
