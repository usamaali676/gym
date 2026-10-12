<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DietPreference;
use App\Enums\Goal;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\ParsesDates;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FoodResource;
use App\Models\Food;
use App\Services\DietPlanService;
use App\Services\FoodLogService;
use App\Services\MealPlanService;
use App\Services\NutritionDayService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Nutrition targets, the day view and the calculator. The server is the only place nutrition maths happens:
 * 150 g of a food with 20 g protein per 100 g comes back as 30 g protein, and the app displays that number.
 */
class NutritionController extends Controller
{
    use ParsesDates;

    public function __construct(
        private readonly DietPlanService $plans,
        private readonly NutritionDayService $days,
        private readonly MealPlanService $meals,
        private readonly FoodLogService $logs,
        private readonly PlanService $training,
    ) {}

    /** The active plan (built on first use from the profile) and the week's targets. */
    public function targets(Request $request): JsonResponse
    {
        $user = $request->user()->load('profile');
        $plan = $this->plans->ensureActive($user);

        if (! $plan) {
            return response()->json(['data' => ['plan' => null, 'reason' => 'profile_incomplete']]);
        }

        $today = $user->localToday();

        return response()->json(['data' => [
            'plan' => $this->plan($plan) + [
                'is_stale' => $this->plans->isStale($plan, $user->profile),
                'explain' => $this->plans->explain($plan),
            ],
            'week' => collect(range(0, 6))->map(function (int $i) use ($user, $plan, $today) {
                $date = $today->startOfWeek()->addDays($i);
                $isTraining = $this->training->isTrainingDay($user, $date);

                return ['date' => $date->toDateString(), 'training' => $isTraining, 'targets' => $plan->targetsFor($isTraining), 'is_today' => $date->isSameDay($today)];
            })->all(),
        ]]);
    }

    /** Switch goal / diet preference and rebuild the targets. */
    public function recalculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goal' => ['required', Rule::in(Goal::values())],
            'diet_preference' => ['required', Rule::in(DietPreference::values())],
            'calorie_cycling' => ['nullable', 'boolean'],
        ]);
        $user = $request->user()->load('profile');
        $profile = $user->profile ?? throw ApiException::onboardingRequired();

        // The profile owns goal and diet preference, so changing them here changes them everywhere.
        $profile->update(['goal' => $data['goal'], 'diet_preference' => $data['diet_preference']]);
        $plan = $this->plans->build($user->fresh('profile'), $request->boolean('calorie_cycling'));

        return response()->json(['data' => [
            'plan' => $this->plan($plan),
            'calorie_cycling_applied' => $request->boolean('calorie_cycling') === $plan->calorie_cycling,
        ]]);
    }

    /** Totals, targets, what is left and food ideas for one day. */
    public function day(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $request->user()->load('profile');
        $date = $this->dateParam($request, $user);

        return response()->json(['data' => $this->days->state($user, $date) + [
            'meals' => collect($this->logs->dayByMeal($user, $date))->map(fn (array $m, string $slot) => [
                'meal' => $slot, 'label' => $m['label'], 'kcal' => $m['kcal'], 'protein_g' => $m['protein_g'],
                'entries' => $m['entries']->map(fn ($l) => LogPresenter::log($l))->all(),
            ])->values()->all(),
        ]]);
    }

    public function history(Request $request): JsonResponse
    {
        $params = $request->validate(['days' => ['nullable', 'integer', 'between:1,90']]);

        return response()->json(['data' => $this->days->history($request->user()->load('profile'), $params['days'] ?? 14)]);
    }

    public function mealPlan(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $request->user()->load('profile');
        $plan = $this->plans->ensureActive($user) ?? throw ApiException::onboardingRequired();
        $date = $this->dateParam($request, $user);

        return response()->json(['data' => $this->meals->suggestDay($plan, $this->training->isTrainingDay($user, $date))]);
    }

    /** What an amount of a food is worth, without saving anything. */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'food_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.1', 'max:5000'],
            'unit' => ['required', Rule::in(['grams', 'serving'])],
            'serving_id' => ['nullable', 'integer', 'required_if:unit,serving'],
        ]);

        $food = Food::query()->visibleTo($request->user())->with('servings')->find($data['food_id']) ?? throw ApiException::notFound('Food not found.');
        $amount = $this->logs->resolveAmount($food, (float) $data['quantity'], $data['unit'], $data['serving_id'] ?? null);

        return response()->json(['data' => [
            'food' => new FoodResource($food),
            'grams' => $amount['grams'],
            'serving_label' => $amount['serving']?->label,
            'macros' => $this->logs->macrosFor($food, $amount['grams']),
        ]]);
    }

    /** @return array<string, mixed> */
    private function plan($plan): array
    {
        return [
            'id' => $plan->id,
            'goal' => $plan->goal->value,
            'diet_preference' => $plan->diet_preference->value,
            'calorie_cycling' => (bool) $plan->calorie_cycling,
            'training_targets' => $plan->targetsFor(true),
            'rest_targets' => $plan->targetsFor(false),
            'protein_g_per_kg' => $plan->protein_g_per_kg,
            'formula_version' => $plan->formula_version,
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }
}
