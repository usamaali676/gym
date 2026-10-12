<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\FoodLog;
use App\Models\Measurement;
use App\Models\PersonalRecord;
use App\Models\PostureSession;
use App\Models\ProgressPhoto;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;

/**
 * The numbers badges are made of. Each metric is computed on demand and remembered for the lifetime of the
 * object, so evaluating twenty badges that share a metric runs one query.
 */
class BadgeMetrics
{
    /** @var array<string, int|float> */
    private array $memo = [];

    public function __construct(
        private readonly User $user,
        private readonly WaterService $water,
        private readonly DailyLogService $dailyLogs,
        private readonly NutritionTotalsService $nutrition,
    ) {}

    /** @return list<string> */
    public static function names(): array
    {
        return [
            'workouts_completed', 'longest_streak', 'perfect_weeks', 'total_volume_kg', 'total_reps', 'prs',
            'posture_sets', 'posture_good_reps', 'posture_sets_over_90',
            'food_days', 'protein_days',
            'water_goal_days', 'creatine_longest', 'sleep_goal_days',
            'measurement_days', 'photos', 'level',
        ];
    }

    public function value(string $metric): float
    {
        return $this->memo[$metric] ??= (float) match ($metric) {
            'workouts_completed' => WorkoutSession::where('user_id', $this->user->id)->where('status', 'completed')->count(),
            'longest_streak' => $this->user->longest_workout_streak,
            'perfect_weeks' => $this->perfectWeeks(),
            'total_volume_kg' => WorkoutSession::where('user_id', $this->user->id)->where('status', 'completed')->sum('volume_kg'),
            'total_reps' => SessionSet::query()->join('workout_sessions', 'workout_sessions.id', '=', 'session_sets.workout_session_id')
                ->where('workout_sessions.user_id', $this->user->id)->where('workout_sessions.status', 'completed')->where('session_sets.is_warmup', false)->sum('session_sets.reps'),
            'prs' => PersonalRecord::where('user_id', $this->user->id)->count(),
            'posture_sets' => PostureSession::where('user_id', $this->user->id)->count(),
            'posture_good_reps' => PostureSession::where('user_id', $this->user->id)->sum('good_reps'),
            'posture_sets_over_90' => PostureSession::where('user_id', $this->user->id)->where('form_score_avg', '>=', 90)->where('total_reps', '>=', 5)->count(),
            'food_days' => FoodLog::where('user_id', $this->user->id)->selectRaw('COUNT(DISTINCT logged_on) as n')->value('n') ?? 0,
            'protein_days' => $this->proteinDays(),
            'water_goal_days' => $this->waterGoalDays(),
            'creatine_longest' => $this->dailyLogs->creatineStats($this->user, $this->user->localToday())['longest'],
            'sleep_goal_days' => DailyLog::where('user_id', $this->user->id)->where('sleep_hours', '>=', (float) ($this->user->profile?->sleep_goal_hours ?? 7.5))->count(),
            'measurement_days' => Measurement::where('user_id', $this->user->id)->count(),
            'photos' => ProgressPhoto::where('user_id', $this->user->id)->count(),
            'level' => $this->user->level,
            default => 0,
        };
    }

    /** ISO weeks in which the user finished at least as many workouts as their weekly target. */
    private function perfectWeeks(): int
    {
        $target = max(1, (int) ($this->user->profile?->days_per_week ?? 5));

        return WorkoutSession::where('user_id', $this->user->id)->where('status', 'completed')
            ->pluck('scheduled_for')
            ->map(fn ($d) => CarbonImmutable::parse($d)->startOfWeek()->toDateString())
            ->countBy()
            ->filter(fn (int $n) => $n >= $target)
            ->count();
    }

    private function proteinDays(): int
    {
        $plan = $this->nutrition->activePlan($this->user);
        if (! $plan) {
            return 0;
        }

        // Compared with the plan's (training day) protein target: the same on rest days unless the user cycles, where it is the same too.
        return FoodLog::where('user_id', $this->user->id)->selectRaw('logged_on, SUM(protein_g) as p')->groupBy('logged_on')->get()
            ->filter(fn ($r) => (float) $r->p >= $plan->protein_g * 0.95)->count();
    }

    private function waterGoalDays(): int
    {
        $goal = $this->water->goalMl($this->user, $this->user->localToday());

        return WaterLog::where('user_id', $this->user->id)->selectRaw('logged_on, SUM(amount_ml) as ml')->groupBy('logged_on')->get()
            ->filter(fn ($r) => (int) $r->ml >= $goal)->count();
    }
}
