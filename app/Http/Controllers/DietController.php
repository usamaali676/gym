<?php

namespace App\Http\Controllers;

use App\Http\Requests\DietRecalculateRequest;
use App\Services\DietPlanService;
use App\Services\MealPlanService;
use App\Services\NutritionTotalsService;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DietController extends Controller
{
    public function __construct(
        private readonly DietPlanService $plans,
        private readonly MealPlanService $meals,
        private readonly NutritionTotalsService $nutrition,
        private readonly PlanService $training,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load('profile');
        $plan = $this->plans->ensureActive($user);

        if (! $plan) {
            return view('diet.index', ['plan' => null, 'user' => $user]);
        }

        $today = $user->localToday();
        $isTraining = $this->training->isTrainingDay($user, $today);

        $week = collect(range(0, 6))->map(function (int $i) use ($user, $plan, $today) {
            $date = $today->startOfWeek()->addDays($i);
            $training = $this->training->isTrainingDay($user, $date);

            return ['date' => $date, 'training' => $training, 'targets' => $plan->targetsFor($training), 'today' => $date->isSameDay($today)];
        });

        return view('diet.index', [
            'user' => $user,
            'plan' => $plan,
            'stale' => $this->plans->isStale($plan, $user->profile),
            'explain' => $this->plans->explain($plan),
            'isTraining' => $isTraining,
            'targets' => $plan->targetsFor($isTraining),
            'totals' => $this->nutrition->totalsFor($user, $today),
            'week' => $week,
            'meals' => $this->meals->suggestDay($plan, $isTraining),
            'today' => $today,
        ]);
    }

    public function recalculate(DietRecalculateRequest $request): RedirectResponse
    {
        $user = $request->user()->load('profile');
        abort_unless($user->profile, 409, 'Finish your profile first.');

        // The profile owns goal and diet preference, so changing them here changes them everywhere.
        $user->profile->update([
            'goal' => $request->input('goal'),
            'diet_preference' => $request->input('diet_preference'),
        ]);

        $plan = $this->plans->build($user->fresh('profile'), $request->boolean('calorie_cycling'));

        $note = $request->boolean('calorie_cycling') && ! $plan->calorie_cycling ? ' Calorie cycling needs both training and rest days, so it stayed off.' : '';

        return redirect()->route('diet.index')->with('status', 'Targets recalculated.'.$note);
    }
}
