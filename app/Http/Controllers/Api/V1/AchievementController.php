<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BadgeService;
use App\Services\LeaderboardService;
use App\Services\StreakService;
use App\Services\XpService;
use App\Support\AppSettingKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Badges, XP and level. Awarded and calculated only on the server, so a modified app cannot grant itself anything. */
class AchievementController extends Controller
{
    public function index(Request $request, BadgeService $badges, XpService $xp, StreakService $streaks): JsonResponse
    {
        $user = $request->user()->load('profile');
        $overview = $badges->overview($user);

        return response()->json(['data' => [
            'xp' => ['total' => $user->xp_total] + $xp->progress($user->xp_total),
            'streak' => ['current' => $streaks->current($user), 'longest' => (int) $user->longest_workout_streak],
            'earned_count' => $overview->where('earned', true)->count(),
            'badges' => $overview->map(fn (array $row) => [
                'slug' => $row['badge']->slug,
                'name' => $row['badge']->name,
                'description' => $row['badge']->description,
                'icon' => $row['badge']->icon,
                'category' => $row['badge']->category,
                'tier' => $row['badge']->tier,
                'xp_reward' => (int) $row['badge']->xp_reward,
                'earned' => $row['earned'],
                'awarded_at' => $row['awarded_at']?->toIso8601String(),
                'progress' => ['value' => $row['value'], 'target' => $row['target'], 'pct' => $row['pct']],
            ])->values()->all(),
        ]]);
    }

    /** Weekly XP ranking among members who opted in. */
    public function leaderboard(Request $request, LeaderboardService $leaderboard): JsonResponse
    {
        $user = $request->user()->load('profile');
        $enabled = (bool) AppSettingKeys::get('features.leaderboard');

        return response()->json(['data' => [
            'enabled' => $enabled,
            'opted_in' => (bool) $user->profile?->leaderboard_opt_in && ! $user->is_guest,
            'entries' => $enabled ? $leaderboard->weekly($user, now($user->timezone()))->all() : [],
        ]]);
    }
}
