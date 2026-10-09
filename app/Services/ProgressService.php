<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\Measurement;
use App\Models\PersonalRecord;
use App\Models\SessionSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Numbers for the progress page: weight and measurement history, personal records and strength curves. */
class ProgressService
{
    public function __construct(
        private readonly BodyMetricsService $body,
        private readonly OneRepMaxService $oneRepMax,
    ) {}

    /** @return Collection<int, Measurement> oldest first */
    public function measurements(User $user, int $days = 180): Collection
    {
        $since = $user->localToday()->subDays($days)->toDateString();

        return Measurement::query()->where('user_id', $user->id)->whereDate('measured_on', '>=', $since)->orderBy('measured_on')->get();
    }

    /**
     * @return array{latest:?Measurement, weight_points:list<array{date:string,weight_kg:float}>, change_30d:?float, trend_per_week:?float, bmi:?float, bmi_category:?string, body_fat:?float, body_fat_source:?string, lean_kg:?float}
     */
    public function summary(User $user): array
    {
        $profile = $user->profile;
        $all = $this->measurements($user);
        $withWeight = $all->whereNotNull('weight_kg')->values();
        $points = $withWeight->map(fn (Measurement $m) => ['date' => $m->measured_on->toDateString(), 'weight_kg' => $m->weight_kg])->all();
        $latestWeight = $withWeight->last()?->weight_kg ?? $profile?->weight_kg;

        $monthAgo = $user->localToday()->subDays(30)->toDateString();
        $past = $withWeight->filter(fn (Measurement $m) => $m->measured_on->toDateString() <= $monthAgo)->last() ?? $withWeight->first();
        $change = ($past && $withWeight->count() > 1 && $latestWeight !== null) ? round($latestWeight - $past->weight_kg, 1) : null;

        $bmi = ($latestWeight && $profile?->height_cm) ? $this->body->bmi($latestWeight, $profile->height_cm) : null;

        // Body fat: a logged value wins; otherwise estimate from tape measurements.
        $latestFat = $all->whereNotNull('body_fat_pct')->last();
        $bodyFat = $latestFat?->body_fat_pct;
        $source = $latestFat ? 'logged' : null;
        if ($bodyFat === null && $profile) {
            $tape = $all->filter(fn (Measurement $m) => $m->neck_cm && $m->waist_cm)->last();
            if ($tape) {
                $bodyFat = $this->body->navyBodyFat($profile->sex, (float) $profile->height_cm, (float) $tape->neck_cm, (float) $tape->waist_cm, $tape->hips_cm ? (float) $tape->hips_cm : null);
                $source = $bodyFat !== null ? 'estimated' : null;
            }
        }

        return [
            'latest' => $all->last(),
            'weight_points' => $points,
            'change_30d' => $change,
            'trend_per_week' => $this->body->weeklyTrend($points),
            'bmi' => $bmi,
            'bmi_category' => $bmi ? $this->body->bmiCategory($bmi) : null,
            'body_fat' => $bodyFat,
            'body_fat_source' => $source,
            'lean_kg' => ($bodyFat !== null && $latestWeight) ? $this->body->composition((float) $latestWeight, (float) $bodyFat)['lean_kg'] : null,
        ];
    }

    /**
     * Personal records grouped by exercise: the best weight, reps and estimated 1RM, newest first.
     *
     * @return Collection<int, array{exercise:Exercise, records:array<string, PersonalRecord>, latest:CarbonInterface}>
     */
    public function records(User $user): Collection
    {
        return PersonalRecord::query()->where('user_id', $user->id)->with('exercise')->get()
            ->groupBy('exercise_id')
            ->map(fn (Collection $rows) => [
                'exercise' => $rows->first()->exercise,
                'records' => $rows->keyBy('type')->all(),
                'latest' => $rows->max('achieved_at'),
            ])
            ->sortByDesc('latest')->values();
    }

    /**
     * Strength curve for one exercise: the best estimated 1RM and the heaviest set of each workout.
     *
     * @return list<array{date:string, e1rm:float, top_weight:float, volume:float}>
     */
    public function strengthSeries(User $user, Exercise $exercise): array
    {
        return SessionSet::query()
            ->join('workout_sessions', 'workout_sessions.id', '=', 'session_sets.workout_session_id')
            ->where('workout_sessions.user_id', $user->id)
            ->where('workout_sessions.status', 'completed')
            ->where('session_sets.exercise_id', $exercise->id)
            ->where('session_sets.is_warmup', false)
            ->where('session_sets.weight_kg', '>', 0)
            ->orderBy('workout_sessions.scheduled_for')
            ->get(['session_sets.weight_kg', 'session_sets.reps', 'workout_sessions.scheduled_for as day'])
            ->groupBy(fn ($r) => CarbonImmutable::parse($r->day)->toDateString())
            ->map(function (Collection $sets, string $date) {
                return [
                    'date' => $date,
                    'e1rm' => round($sets->max(fn ($s) => $this->oneRepMax->estimate((float) $s->weight_kg, (int) $s->reps)), 1),
                    'top_weight' => (float) $sets->max('weight_kg'),
                    'volume' => round($sets->sum(fn ($s) => $s->weight_kg * $s->reps), 1),
                ];
            })->values()->all();
    }
}
