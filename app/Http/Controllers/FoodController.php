<?php

namespace App\Http\Controllers;

use App\Enums\Meal;
use App\Services\DietPlanService;
use App\Services\FoodLogService;
use App\Services\FoodSearchService;
use App\Services\NutritionDayService;
use App\Support\FoodPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FoodController extends Controller
{
    public function __construct(
        private readonly FoodLogService $logs,
        private readonly NutritionDayService $days,
        private readonly FoodSearchService $search,
        private readonly DietPlanService $plans,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load('profile');
        $today = $user->localToday();
        $date = $this->parseDate($request->query('date'), $user->timezone()) ?? $today;
        $date = $date->lt($today->subDays(365)) ? $today->subDays(365) : ($date->gt($today->addDay()) ? $today->addDay() : $date);

        $plan = $this->plans->ensureActive($user);
        $byMeal = $this->logs->dayByMeal($user, $date);

        return view('food.index', [
            'date' => $date,
            'today' => $today,
            'plan' => $plan,
            'config' => [
                'date' => $date->toDateString(),
                'today' => $today->toDateString(),
                'yesterday' => $date->subDay()->toDateString(),
                'meals' => collect(Meal::cases())->mapWithKeys(fn (Meal $m) => [$m->value => $m->label()])->all(),
                'logs' => collect($byMeal)->flatMap(fn ($m) => $m['entries'])->map(fn ($l) => FoodPresenter::log($l))->values()->all(),
                'day' => $this->days->state($user, $date, $plan),
                'recent' => $this->search->recent($user)->map(fn ($f) => FoodPresenter::food($f, $user->id))->all(),
                'history' => $this->days->history($user, 14, $plan),
                'dietPreference' => $user->profile?->diet_preference?->value,
                'hasPlan' => (bool) $plan,
                'urls' => [
                    'search' => route('api.foods.search'),
                    'store' => route('api.food-logs.store'),
                    'batch' => route('api.food-logs.batch'),
                    'copy' => route('api.food-logs.copy'),
                    'log' => url('/api/food-logs'),
                    'customFood' => route('api.foods.store'),
                    'food' => url('/api/foods'),
                    'diet' => route('diet.index'),
                    'page' => route('food.index'),
                ],
            ],
        ]);
    }

    private function parseDate(mixed $value, string $tz): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
