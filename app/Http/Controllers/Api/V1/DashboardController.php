<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkoutSession;
use App\Services\HabitService;
use App\Services\Membership\EntitlementService;
use App\Services\NutritionTotalsService;
use App\Services\PlanService;
use App\Services\StreakService;
use App\Services\XpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Everything the home screen shows, in one call. */
class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        PlanService $plans,
        XpService $xp,
        StreakService $streaks,
        NutritionTotalsService $nutrition,
        HabitService $habits,
        EntitlementService $entitlements,
    ): JsonResponse {
        $user = $request->user()->load('profile.plan');
        $now = $user->localNow();
        $today = $user->localToday();
        $access = $entitlements->forRequest($request);

        $day = $plans->dayFor($user, $today);
        $overview = $plans->weekOverview($user);
        $todayEntry = $overview->firstWhere('is_today', true);
        $open = WorkoutSession::query()->where('user_id', $user->id)->whereIn('status', [SessionStatus::InProgress, SessionStatus::Paused])->latest('id')->first();

        $dietPlan = $access->allows('nutrition') ? $nutrition->activePlan($user) : null;
        $quotes = config('ironpulse.quotes');

        return response()->json(['data' => [
            'greeting_period' => match (true) {
                $now->hour < 5 => 'late_night', $now->hour < 12 => 'morning', $now->hour < 18 => 'afternoon', default => 'evening',
            },
            'today' => $today->toDateString(),
            'workout' => [
                'status' => $todayEntry['status'] ?? 'rest',
                'day' => $day ? ['id' => $day->id, 'weekday' => $day->day_of_week, 'title' => $day->title(), 'label' => $day->label, 'focus' => $day->focus, 'is_rest' => (bool) $day->is_rest] : null,
                'estimated_minutes' => $day ? $plans->estimatedMinutes($day) : 0,
                'open_session_id' => $open?->id,
            ],
            'week' => $plans->weekProgress($user, $overview) + [
                'days' => $overview->map(fn (array $e) => ['date' => $e['date']->toDateString(), 'weekday' => $e['date']->dayOfWeekIso, 'status' => $e['status'], 'is_today' => $e['is_today']])->values()->all(),
            ],
            'streak' => $streaks->current($user),
            'xp' => ['total' => $user->xp_total] + $xp->progress($user->xp_total),
            'nutrition' => $dietPlan ? [
                'targets' => $nutrition->targetsFor($user, $today, $dietPlan),
                'totals' => $nutrition->totalsFor($user, $today),
            ] : null,
            'habits' => $habits->state($user, $today, history: false),
            'quote' => $quotes[$now->dayOfYear % count($quotes)],
            'membership' => ['plan_slug' => $access->plan?->slug, 'entitlements' => $access->map()],
        ]]);
    }
}
