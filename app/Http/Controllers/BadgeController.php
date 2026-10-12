<?php

namespace App\Http\Controllers;

use App\Services\BadgeService;
use App\Services\LeaderboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BadgeController extends Controller
{
    public function index(Request $request, BadgeService $badges, LeaderboardService $leaderboard): View
    {
        $user = $request->user()->load('profile');
        $overview = $badges->overview($user);

        return view('badges.index', [
            'overview' => $overview,
            'groups' => $overview->groupBy(fn ($row) => $row['badge']->category),
            'earnedCount' => $overview->where('earned', true)->count(),
            'leaderboard' => $leaderboard->weekly($user, now($user->timezone())),
            'optedIn' => (bool) $user->profile?->leaderboard_opt_in && ! $user->is_guest,
        ]);
    }
}
