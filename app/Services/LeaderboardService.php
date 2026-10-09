<?php

namespace App\Services;

use App\Models\User;
use App\Models\XpEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Weekly XP leaderboard, strictly opt-in: only users who switched "Show me on the leaderboard" on appear,
 * under the public name they chose. Guests, and everyone who has not opted in, are never listed or ranked.
 */
class LeaderboardService
{
    /**
     * @return Collection<int, array{rank:int, name:string, xp:int, level:int, me:bool}>
     */
    public function weekly(?User $viewer, CarbonInterface $now, int $limit = 10): Collection
    {
        $start = $now->copy()->startOfWeek();

        $rows = XpEvent::query()
            ->join('users', 'users.id', '=', 'xp_events.user_id')
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('profiles.leaderboard_opt_in', true)
            ->where('users.is_guest', false)
            ->where('xp_events.created_at', '>=', $start)
            ->where('xp_events.amount', '>', 0)
            ->groupBy('users.id', 'users.name', 'users.level', 'profiles.public_name')
            ->selectRaw('users.id as user_id, users.level as level, COALESCE(NULLIF(profiles.public_name, \'\'), users.name) as display, SUM(xp_events.amount) as xp')
            ->orderByDesc('xp')
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        return $rows->values()->map(fn ($r, int $i) => [
            'rank' => $i + 1,
            'name' => $r->display,
            'xp' => (int) $r->xp,
            'level' => (int) $r->level,
            'me' => $viewer !== null && (int) $r->user_id === $viewer->id,
        ]);
    }
}
