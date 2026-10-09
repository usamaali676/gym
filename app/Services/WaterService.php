<?php

namespace App\Services;

use App\Models\User;
use App\Models\WaterLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Water intake: quick-add glasses, undo, and the daily goal (35 ml per kg, more on training days, or the user's own number). */
class WaterService
{
    public const MAX_SINGLE_ML = 2000;

    public const MAX_DAY_ML = 12000;

    public function __construct(
        private readonly DietCalculatorService $calculator,
        private readonly NutritionTotalsService $nutrition,
        private readonly PlanService $plans,
        private readonly XpService $xp,
    ) {}

    /** The goal for a date in ml: the user's own goal if set, else the diet plan's / formula's with the training-day bonus. */
    public function goalMl(User $user, CarbonInterface $date): int
    {
        $profile = $user->profile;
        if ($profile?->water_goal_ml) {
            return (int) $profile->water_goal_ml;
        }

        $training = $this->plans->isTrainingDay($user, $date);
        $plan = $this->nutrition->activePlan($user);
        $base = $plan?->water_ml ?? (int) (round(config('ironpulse.water.ml_per_kg') * ($profile?->weight_kg ?? 70) / 50) * 50);

        return $this->calculator->waterGoal($base, $training);
    }

    public function totalFor(User $user, CarbonInterface $date): int
    {
        return (int) WaterLog::query()->where('user_id', $user->id)->whereDate('logged_on', $date->toDateString())->sum('amount_ml');
    }

    /**
     * @return array{total_ml:int, goal_ml:int, pct:int, reached:bool, xp:int, entries:list<array{id:int, amount_ml:int, at:string}>}
     */
    public function state(User $user, CarbonInterface $date, int $xp = 0): array
    {
        $total = $this->totalFor($user, $date);
        $goal = $this->goalMl($user, $date);
        $entries = WaterLog::query()->where('user_id', $user->id)->whereDate('logged_on', $date->toDateString())->orderBy('logged_at')->orderBy('id')->get()
            ->map(fn (WaterLog $w) => ['id' => $w->id, 'amount_ml' => $w->amount_ml, 'at' => $w->logged_at->format('H:i')])->all();

        return [
            'total_ml' => $total,
            'goal_ml' => $goal,
            'pct' => $goal > 0 ? (int) min(100, round($total / $goal * 100)) : 0,
            'reached' => $total >= $goal,
            'xp' => $xp,
            'entries' => $entries,
        ];
    }

    /** Add a drink. Awards the daily water XP once, the moment the goal is first reached. */
    public function add(User $user, CarbonInterface $date, int $amountMl): array
    {
        $amountMl = max(10, min(self::MAX_SINGLE_ML, $amountMl));
        $xp = 0;

        DB::transaction(function () use ($user, $date, $amountMl, &$xp) {
            $before = $this->totalFor($user, $date);
            if ($before >= self::MAX_DAY_ML) {
                return;
            }
            WaterLog::create([
                'user_id' => $user->id, 'logged_on' => $date->toDateString(), 'amount_ml' => min($amountMl, self::MAX_DAY_ML - $before), 'logged_at' => now(),
            ]);

            $goal = $this->goalMl($user, $date);
            if ($before < $goal && $before + $amountMl >= $goal) {
                $event = $this->xp->award($user, 'water_goal', (int) config('ironpulse.xp.water_goal'), 'Water goal reached', 'water_day', (int) $date->format('Ymd'));
                $xp = $event?->amount ?? 0;
            }
        });

        return $this->state($user, $date, $xp);
    }

    /** Undo the most recent drink of the day (XP already paid stays paid: it is a tiny, once-a-day amount). */
    public function undoLast(User $user, CarbonInterface $date): array
    {
        WaterLog::query()->where('user_id', $user->id)->whereDate('logged_on', $date->toDateString())->orderByDesc('id')->first()?->delete();

        return $this->state($user, $date);
    }
}
