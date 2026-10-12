<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\WorkoutSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Awards badges. A badge is earned when its metric (see BadgeMetrics) reaches the badge's threshold.
 * Evaluation is cheap and idempotent: it only looks at badges the user does not have yet, and can be limited to
 * the categories an event could have changed (a posture set cannot earn a hydration badge).
 */
class BadgeService
{
    public function __construct(
        private readonly XpService $xp,
        private readonly WaterService $water,
        private readonly DailyLogService $dailyLogs,
        private readonly NutritionTotalsService $nutrition,
    ) {}

    /**
     * Award everything the user has now earned.
     *
     * @param  list<string>|null  $categories  null checks every category
     * @param  array<string, mixed>  $context  stored with the award (e.g. which workout caused it)
     * @return list<Badge> the badges awarded by this call
     */
    public function evaluate(User $user, ?array $categories = null, array $context = []): array
    {
        $owned = UserBadge::where('user_id', $user->id)->pluck('badge_id');
        $candidates = Badge::query()->where('is_active', true)->whereNotIn('id', $owned)
            ->when($categories, fn ($q) => $q->whereIn('category', $categories))->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $user->loadMissing('profile');
        $metrics = new BadgeMetrics($user, $this->water, $this->dailyLogs, $this->nutrition);
        $awarded = [];

        foreach ($candidates as $badge) {
            $value = $metrics->value($badge->criteria['metric'] ?? '');
            if ($value < ($badge->criteria['gte'] ?? PHP_INT_MAX)) {
                continue;
            }

            $created = UserBadge::firstOrCreate(
                ['user_id' => $user->id, 'badge_id' => $badge->id],
                ['awarded_at' => now(), 'context' => $context + ['value' => $value]],
            );
            if (! $created->wasRecentlyCreated) {
                continue; // a parallel request awarded it first
            }
            if ($badge->xp_reward > 0) {
                $this->xp->award($user, 'badge', $badge->xp_reward, "Badge: {$badge->name}", 'badge', $badge->id);
            }
            $awarded[] = $badge;
        }

        return $awarded;
    }

    /** Hook used by WorkoutSessionService::finish(). @return list<Badge> */
    public function evaluateAfterWorkout(User $user, WorkoutSession $session): array
    {
        return $this->evaluate($user, ['training', 'level', 'posture'], ['source' => 'workout', 'id' => $session->id]);
    }

    /**
     * Every badge with the user's progress towards it, for the badges page.
     *
     * @return Collection<int, array{badge: Badge, earned: bool, awarded_at: ?CarbonInterface, value: float, target: float, pct: int}>
     */
    public function overview(User $user): Collection
    {
        $user->loadMissing('profile');
        $metrics = new BadgeMetrics($user, $this->water, $this->dailyLogs, $this->nutrition);
        $earned = UserBadge::where('user_id', $user->id)->get()->keyBy('badge_id');

        return Badge::query()->where('is_active', true)->orderBy('id')->get()->map(function (Badge $badge) use ($metrics, $earned) {
            $target = (float) ($badge->criteria['gte'] ?? 1);
            $mine = $earned->get($badge->id);
            $value = $mine ? $target : min($target, $metrics->value($badge->criteria['metric'] ?? ''));

            return [
                'badge' => $badge,
                'earned' => $mine !== null,
                'awarded_at' => $mine?->awarded_at,
                'value' => $value,
                'target' => $target,
                'pct' => (int) min(100, round($value / max(1, $target) * 100)),
            ];
        });
    }
}
