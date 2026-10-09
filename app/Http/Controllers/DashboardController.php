<?php

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Models\WorkoutSession;
use App\Services\NutritionTotalsService;
use App\Services\PlanService;
use App\Services\StreakService;
use App\Services\XpService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        PlanService $plans,
        XpService $xp,
        StreakService $streaks,
        NutritionTotalsService $nutrition,
    ): View {
        $user = $request->user()->load('profile');
        $now = $user->localNow();
        $today = $user->localToday();

        $day = $plans->dayFor($user, $today);
        $overview = $plans->weekOverview($user);
        $todayEntry = $overview->firstWhere('is_today', true);

        $dietPlan = $nutrition->activePlan($user);
        $quotes = config('ironpulse.quotes');

        $openSession = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [SessionStatus::InProgress, SessionStatus::Paused])
            ->latest('id')
            ->first();

        return view('dashboard', [
            'user' => $user,
            'greeting' => match (true) {
                $now->hour < 5 => 'Late night grind',
                $now->hour < 12 => 'Good morning',
                $now->hour < 18 => 'Good afternoon',
                default => 'Good evening',
            },
            'now' => $now,
            'day' => $day,
            'dayMinutes' => $day ? $plans->estimatedMinutes($day) : 0,
            'todayStatus' => $todayEntry['status'] ?? 'rest',
            'openSession' => $openSession,
            'overview' => $overview,
            'week' => $plans->weekProgress($user, $overview),
            'streak' => $streaks->current($user),
            'xp' => $xp->progress($user->xp_total),
            'dietPlan' => $dietPlan,
            'totals' => $nutrition->totalsFor($user, $today),
            'targets' => $nutrition->targetsFor($user, $today, $dietPlan),
            'quote' => $quotes[$now->dayOfYear % count($quotes)],
        ]);
    }
}
