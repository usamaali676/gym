<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\FoodLog;
use App\Models\Measurement;
use App\Models\PersonalRecord;
use App\Models\PostureSession;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\WaterLog;
use App\Models\WeeklyReport;
use App\Models\WorkoutSession;
use App\Models\XpEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Builds the Monday-to-Sunday report for one user. The result is a plain array (stored as the report snapshot),
 * so the page, the PDF, the email and the public share link all show exactly the same numbers.
 */
class WeeklyReportService
{
    public const SCHEMA = 1;

    /** Sections that are never shown on a shared link: they describe the body, food and sleep of a private person. */
    public const PRIVATE_SECTIONS = ['body', 'nutrition', 'water', 'creatine', 'sleep'];

    public function __construct(
        private readonly PlanService $plans,
        private readonly WaterService $water,
        private readonly NutritionTotalsService $nutrition,
        private readonly StreakService $streaks,
        private readonly XpService $xp,
    ) {}

    public function weekStart(User $user, ?CarbonInterface $anchor = null): CarbonImmutable
    {
        $anchor = $anchor ? CarbonImmutable::parse($anchor->toDateString(), $user->timezone()) : $user->localToday();

        return $anchor->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /** The stored report for a week, rebuilt whenever the week is still running (or has no snapshot yet). */
    public function forWeek(User $user, CarbonImmutable $weekStart, bool $refresh = false): WeeklyReport
    {
        $report = WeeklyReport::where('user_id', $user->id)->whereDate('week_start', $weekStart->toDateString())->first();
        $complete = $weekStart->addDays(6)->lt($user->localToday());

        if (! $report || $refresh || ! $complete || ($report->payload['schema'] ?? 0) !== self::SCHEMA) {
            $payload = $this->build($user, $weekStart);
            $report ??= new WeeklyReport(['user_id' => $user->id, 'week_start' => $weekStart->toDateString()]);
            $report->payload = $payload;
            $report->save();
        }

        return $report;
    }

    /** @return array<string, mixed> */
    public function build(User $user, CarbonImmutable $weekStart): array
    {
        $user->loadMissing('profile');
        $today = $user->localToday();
        $weekEnd = $weekStart->addDays(6);
        $elapsed = max(0, min(7, (int) $weekStart->diffInDays($today->min($weekEnd), false) + 1));
        $dates = collect(range(0, 6))->map(fn (int $i) => $weekStart->addDays($i));
        $past = $dates->filter(fn (CarbonImmutable $d) => $d->lte($today))->values();

        $training = $this->training($user, $weekStart, $dates, $today);
        $nutrition = $this->nutritionSection($user, $past);
        $water = $this->waterSection($user, $past);
        $sleep = $this->sleepSection($user, $past);
        $body = $this->bodySection($user, $weekStart, $weekEnd);
        $posture = $this->posture($user, $weekStart, $weekEnd);
        $creatine = $this->creatineSection($user, $past);
        $records = $this->records($user, $weekStart, $weekEnd);
        $xp = $this->xpSection($user, $weekStart, $weekEnd);
        $badges = $this->badges($user, $weekStart, $weekEnd);

        $report = [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toIso8601String(),
            'name' => $this->displayName($user),
            'period' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'label' => $this->label($weekStart, $weekEnd),
                'complete' => $weekEnd->lt($today),
                'days_elapsed' => $elapsed,
            ],
            'training' => $training,
            'records' => $records,
            'nutrition' => $nutrition,
            'water' => $water,
            'creatine' => $creatine,
            'sleep' => $sleep,
            'body' => $body,
            'posture' => $posture,
            'xp' => $xp,
            'badges' => $badges,
            'streak' => $this->streaks->current($user),
        ];

        $report['highlights'] = $this->highlights($report);
        $report['focus'] = $this->focus($report);

        return $report;
    }

    /** What a public link may show: the private sections are removed, not just hidden. */
    public function shareable(array $payload): array
    {
        foreach (self::PRIVATE_SECTIONS as $key) {
            unset($payload[$key]);
        }
        // Highlights and focus were written from the full data, so rebuild them from what is left.
        $payload['highlights'] = $this->highlights($payload);
        unset($payload['focus']);

        return $payload;
    }

    private function displayName(User $user): string
    {
        $public = trim((string) $user->profile?->public_name);
        if ($public !== '') {
            return $public;
        }

        return $user->is_guest ? 'An IronPulse athlete' : Str::of($user->name)->before(' ')->toString();
    }

    private function label(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->month === $end->month
            ? $start->format('j').' - '.$end->format('j M Y')
            : $start->format('j M').' - '.$end->format('j M Y');
    }

    /** UTC-safe bounds for timestamp columns, from the user's local week. */
    private function bounds(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $zone = config('app.timezone');

        return [
            CarbonImmutable::parse($start->toDateString(), $user->timezone())->startOfDay()->setTimezone($zone),
            CarbonImmutable::parse($end->toDateString(), $user->timezone())->endOfDay()->setTimezone($zone),
        ];
    }

    private function training(User $user, CarbonImmutable $weekStart, $dates, CarbonImmutable $today): array
    {
        $overview = $this->plans->weekOverview($user, $weekStart);
        $sessions = WorkoutSession::query()
            ->where('user_id', $user->id)->where('status', 'completed')
            ->whereDate('scheduled_for', '>=', $weekStart->toDateString())->whereDate('scheduled_for', '<=', $weekStart->addDays(6)->toDateString())
            ->get();

        $planned = $overview->where('is_training', true)->count();
        $done = $overview->where('status', 'completed')->where('is_training', true)->count();
        $rated = $sessions->whereNotNull('rating');

        return [
            'planned' => $planned,
            'completed' => $done,
            'extra' => max(0, $sessions->count() - $done),
            'skipped' => $overview->where('status', 'skipped')->count(),
            'missed' => $overview->where('status', 'missed')->count(),
            'minutes' => (int) round($sessions->sum('active_seconds') / 60),
            'volume_kg' => (int) round($sessions->sum('volume_kg')),
            'sets' => (int) $sessions->sum('sets_completed'),
            'avg_rating' => $rated->isEmpty() ? null : round($rated->avg('rating'), 1),
            'days' => $overview->map(fn (array $d) => [
                'date' => $d['date']->toDateString(),
                'short' => $d['date']->format('D'),
                'label' => $d['day']?->is_rest ? 'Rest' : ($d['day']?->label ? ucfirst(strtolower($d['day']->label)) : null),
                'status' => $d['status'],
            ])->all(),
        ];
    }

    private function records(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->bounds($user, $start, $end);

        return PersonalRecord::query()->where('user_id', $user->id)->whereBetween('achieved_at', [$from, $to])->with('exercise')->get()
            ->groupBy('exercise_id')
            ->map(function ($rows) {
                $best = $rows->firstWhere('type', 'est_1rm') ?? $rows->firstWhere('type', 'max_weight') ?? $rows->first();

                return [
                    'exercise' => $best->exercise?->name ?? 'Exercise',
                    'type' => $best->type,
                    'value' => (float) $best->value,
                    'weight_kg' => $best->weight_kg !== null ? (float) $best->weight_kg : null,
                    'reps' => $best->reps,
                    'previous' => $best->previous_value !== null ? (float) $best->previous_value : null,
                ];
            })
            ->sortByDesc('value')->take(8)->values()->all();
    }

    private function nutritionSection(User $user, $past): ?array
    {
        if ($past->isEmpty()) {
            return null;
        }
        $plan = $this->nutrition->activePlan($user);
        $rows = FoodLog::query()->where('user_id', $user->id)
            ->whereDate('logged_on', '>=', $past->first()->toDateString())->whereDate('logged_on', '<=', $past->last()->toDateString())
            ->selectRaw('logged_on, SUM(kcal) as kcal, SUM(protein_g) as protein')->groupBy('logged_on')->get()
            ->mapWithKeys(fn ($r) => [CarbonImmutable::parse($r->logged_on)->toDateString() => $r]);

        $logged = $rows->filter(fn ($r) => (float) $r->kcal > 0);
        $proteinHit = 0;
        $calorieHit = 0;
        $targetKcal = [];
        $targetProtein = [];
        foreach ($past as $d) {
            $targets = $plan?->targetsFor($this->plans->isTrainingDay($user, $d));
            if (! $targets) {
                continue;
            }
            $targetKcal[] = $targets['calories'];
            $targetProtein[] = $targets['protein_g'];
            $row = $rows->get($d->toDateString());
            if (! $row || (float) $row->kcal <= 0) {
                continue;
            }
            if ((float) $row->protein >= 0.9 * $targets['protein_g']) {
                $proteinHit++;
            }
            if (abs((float) $row->kcal - $targets['calories']) <= 0.1 * $targets['calories']) {
                $calorieHit++;
            }
        }

        return [
            'has_plan' => $plan !== null,
            'days_logged' => $logged->count(),
            'days_tracked' => $past->count(),
            'avg_kcal' => $logged->isEmpty() ? null : (int) round($logged->avg('kcal')),
            'avg_protein_g' => $logged->isEmpty() ? null : (int) round($logged->avg('protein')),
            'target_kcal' => $targetKcal ? (int) round(array_sum($targetKcal) / count($targetKcal)) : null,
            'target_protein_g' => $targetProtein ? (int) round(array_sum($targetProtein) / count($targetProtein)) : null,
            'protein_days' => $proteinHit,
            'calorie_days' => $calorieHit,
        ];
    }

    private function waterSection(User $user, $past): ?array
    {
        if ($past->isEmpty()) {
            return null;
        }
        $totals = WaterLog::query()->where('user_id', $user->id)
            ->whereDate('logged_on', '>=', $past->first()->toDateString())->whereDate('logged_on', '<=', $past->last()->toDateString())
            ->selectRaw('logged_on, SUM(amount_ml) as ml')->groupBy('logged_on')->get()
            ->mapWithKeys(fn ($r) => [CarbonImmutable::parse($r->logged_on)->toDateString() => (int) $r->ml]);

        $hit = 0;
        $goals = [];
        foreach ($past as $d) {
            $goal = $this->water->goalMl($user, $d);
            $goals[] = $goal;
            if (($totals[$d->toDateString()] ?? 0) >= $goal) {
                $hit++;
            }
        }

        return [
            'days_hit' => $hit,
            'days_tracked' => $past->count(),
            'avg_ml' => (int) round($totals->sum() / $past->count()),
            'goal_ml' => (int) round(array_sum($goals) / count($goals)),
        ];
    }

    private function dailyLogs(User $user, $past)
    {
        return DailyLog::query()->where('user_id', $user->id)
            ->whereDate('log_date', '>=', $past->first()->toDateString())->whereDate('log_date', '<=', $past->last()->toDateString())->get();
    }

    private function creatineSection(User $user, $past): ?array
    {
        if ($past->isEmpty()) {
            return null;
        }

        return ['days_taken' => $this->dailyLogs($user, $past)->where('creatine_taken', true)->count(), 'days_tracked' => $past->count()];
    }

    private function sleepSection(User $user, $past): ?array
    {
        if ($past->isEmpty()) {
            return null;
        }
        $goal = (float) ($user->profile?->sleep_goal_hours ?? 7.5);
        $nights = $this->dailyLogs($user, $past)->whereNotNull('sleep_hours');

        return [
            'goal' => $goal,
            'nights_logged' => $nights->count(),
            'avg_hours' => $nights->isEmpty() ? null : round($nights->avg('sleep_hours'), 1),
            'nights_on_goal' => $nights->filter(fn ($l) => $l->sleep_hours >= $goal - 0.25)->count(),
        ];
    }

    private function bodySection(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $inWeek = Measurement::query()->where('user_id', $user->id)->whereNotNull('weight_kg')
            ->whereDate('measured_on', '>=', $start->toDateString())->whereDate('measured_on', '<=', $end->toDateString())
            ->orderBy('measured_on')->get();
        $before = Measurement::query()->where('user_id', $user->id)->whereNotNull('weight_kg')
            ->whereDate('measured_on', '<', $start->toDateString())->orderByDesc('measured_on')->first();

        $last = $inWeek->last();
        $from = $before ?? $inWeek->first();

        return [
            'weigh_ins' => $inWeek->count(),
            'weight_kg' => $last?->weight_kg,
            'change_kg' => ($last && $from && $from->id !== $last->id) ? round($last->weight_kg - $from->weight_kg, 1) : null,
        ];
    }

    private function posture(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->bounds($user, $start, $end);
        $sessions = PostureSession::query()->where('user_id', $user->id)->whereBetween('started_at', [$from, $to])->with('exercise')->get();

        $best = $sessions->sortByDesc('form_score_avg')->first();

        return [
            'sessions' => $sessions->count(),
            'total_reps' => (int) $sessions->sum('total_reps'),
            'good_reps' => (int) $sessions->sum('good_reps'),
            'avg_score' => $sessions->isEmpty() ? null : (int) round($sessions->avg('form_score_avg')),
            'best' => $best ? ['exercise' => $best->exercise?->name ?? 'Exercise', 'score' => (int) $best->form_score_avg] : null,
        ];
    }

    private function xpSection(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->bounds($user, $start, $end);
        $earned = (int) XpEvent::where('user_id', $user->id)->where('amount', '>', 0)->whereBetween('created_at', [$from, $to])->sum('amount');

        return ['earned' => $earned, 'level' => $this->xp->levelFor((int) $user->xp_total), 'total' => (int) $user->xp_total];
    }

    private function badges(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->bounds($user, $start, $end);

        return UserBadge::query()->where('user_id', $user->id)->whereBetween('awarded_at', [$from, $to])->with('badge')->get()
            ->map(fn (UserBadge $b) => ['name' => $b->badge->name, 'icon' => $b->badge->icon ?? null])->values()->all();
    }

    /** @return list<string> */
    private function highlights(array $r): array
    {
        $out = [];
        $t = $r['training'];
        if ($t['planned'] > 0 && $t['completed'] >= $t['planned']) {
            $out[] = "You finished every planned session ({$t['completed']} of {$t['planned']}).";
        } elseif ($t['completed'] > 0) {
            $out[] = "{$t['completed']} of {$t['planned']} planned sessions done.";
        }
        if ($r['records']) {
            $n = count($r['records']);
            $top = $r['records'][0];
            $out[] = $n === 1 ? "New personal record on {$top['exercise']}." : "{$n} personal records, led by {$top['exercise']}.";
        }
        if ($t['volume_kg'] > 0) {
            $out[] = 'You lifted '.number_format($t['volume_kg']).' kg in total across '.$t['sets'].' sets.';
        }
        if (($r['posture']['sessions'] ?? 0) > 0 && ($r['posture']['avg_score'] ?? 0) >= 85) {
            $out[] = "Strong form: Posture Lab average {$r['posture']['avg_score']}/100.";
        }
        if (($r['nutrition']['protein_days'] ?? 0) >= 5) {
            $out[] = "Protein on target on {$r['nutrition']['protein_days']} days.";
        }
        if (($r['sleep']['avg_hours'] ?? 0) >= ($r['sleep']['goal'] ?? 99) - 0.25) {
            $out[] = "Sleep averaged {$r['sleep']['avg_hours']} h, right on goal.";
        }
        if (($r['creatine']['days_taken'] ?? 0) >= 7) {
            $out[] = 'Creatine every day this week.';
        }
        if (($r['streak'] ?? 0) >= 2) {
            $out[] = "A {$r['streak']}-session workout streak is running.";
        }
        if ($r['badges']) {
            $out[] = 'Badges earned: '.collect($r['badges'])->pluck('name')->implode(', ').'.';
        }

        return array_slice($out, 0, 6);
    }

    /** Up to three concrete things to improve, picked from the weakest areas. @return list<string> */
    private function focus(array $r): array
    {
        $out = [];
        $t = $r['training'];
        $missing = $t['planned'] - $t['completed'];
        if ($r['period']['complete'] && $missing > 0) {
            $out[] = "Schedule the sessions you missed ({$missing}). Training days you plan the night before are the ones that happen.";
        }
        $n = $r['nutrition'] ?? null;
        if ($n && $n['has_plan'] && $n['days_logged'] < min(5, $n['days_tracked'])) {
            $out[] = 'Log food on more days. Even rough logging shows whether protein is where it needs to be.';
        } elseif ($n && $n['has_plan'] && $n['days_logged'] > 0 && $n['protein_days'] < (int) ceil($n['days_logged'] / 2)) {
            $out[] = 'Protein was on target on '.$n['protein_days'].' of '.$n['days_logged'].' logged days. Anchor each meal with a protein source.';
        }
        $s = $r['sleep'] ?? null;
        if ($s && $s['avg_hours'] !== null && $s['avg_hours'] < $s['goal'] - 0.5) {
            $out[] = "Sleep averaged {$s['avg_hours']} h against a {$s['goal']} h goal. Moving bedtime 30 minutes earlier is the cheapest recovery upgrade.";
        } elseif ($s && $s['nights_logged'] === 0) {
            $out[] = 'Log your sleep for a few nights so recovery shows up in this report.';
        }
        $w = $r['water'] ?? null;
        if ($w && $w['days_hit'] < intdiv($w['days_tracked'], 2)) {
            $out[] = 'Water goal was reached on '.$w['days_hit'].' of '.$w['days_tracked'].' days. A bottle by your training bag helps.';
        }
        if ($t['completed'] > 0 && ($r['posture']['sessions'] ?? 0) === 0) {
            $out[] = 'Try one Posture Lab set on your first lift of the week to check form.';
        } elseif (($r['posture']['avg_score'] ?? 100) < 75 && ($r['posture']['sessions'] ?? 0) > 0) {
            $out[] = 'Form scores were below 75. Lower the weight a little and slow the lowering phase.';
        }
        if (($r['body']['weigh_ins'] ?? 1) === 0) {
            $out[] = 'Log your weight once this week so the trend line stays trustworthy.';
        }

        return array_slice($out, 0, 3);
    }
}
