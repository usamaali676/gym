<?php

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Models\Badge;
use App\Models\PlanDay;
use App\Models\ProgressionTarget;
use App\Models\WorkoutSession;
use App\Services\PlanService;
use App\Services\WorkoutPageData;
use App\Services\WorkoutSessionService;
use App\Services\XpService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkoutController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly WorkoutSessionService $sessions,
    ) {}

    /** Entry point of the "Train" button: resume, start today's workout, or choose a day. */
    public function start(Request $request): View|RedirectResponse
    {
        $user = $request->user()->load('profile.plan');

        if ($open = $this->sessions->findOpen($user)) {
            return redirect()->route('workout.play', $open)
                ->with('status', 'You have a workout in progress. Finish it or abandon it first.');
        }

        $dow = (int) $request->query('day', 0);
        $date = $this->parseDate($request->query('date'), $user->timezone());

        if ($dow === 0) {
            $today = $user->localToday();
            $todayDay = $this->plans->dayFor($user, $today);

            if (! $todayDay || ! $this->plans->isTrainingDay($user, $today)) {
                return $this->choose($user);
            }
            $dow = $todayDay->day_of_week;
            $date = $today;
        }

        $plan = $this->plans->planFor($user);
        $day = $plan ? $this->plans->days($plan)->get($dow) : null;
        abort_unless($day instanceof PlanDay, 404);
        $date ??= $this->plans->dateForWeekday($user, $dow);

        try {
            $session = $this->sessions->start($user, $day, $date);
        } catch (ValidationException $e) {
            return redirect()->route('plan.day', ['day' => $dow])->withErrors($e->errors());
        }

        return $session->status === SessionStatus::Completed
            ? redirect()->route('workout.summary', $session)
            : redirect()->route('workout.play', $session);
    }

    /** The full-screen guided session. */
    public function play(Request $request, WorkoutSession $session, WorkoutPageData $data): View|RedirectResponse
    {
        Gate::authorize('view', $session);

        if ($session->status === SessionStatus::Completed) {
            return redirect()->route('workout.summary', $session);
        }
        if (! $session->isOpen()) {
            return redirect()->route('plan.index')->with('status', 'That workout has been closed.');
        }

        $user = $request->user()->load('profile');

        return view('workout.play', ['session' => $session, 'config' => $data->build($user, $session)]);
    }

    /** Results screen: duration, volume, XP, PRs, next targets. */
    public function summary(Request $request, WorkoutSession $session, XpService $xp): View|RedirectResponse
    {
        Gate::authorize('view', $session);

        if ($session->status !== SessionStatus::Completed) {
            return $session->isOpen() ? redirect()->route('workout.play', $session) : redirect()->route('plan.index');
        }

        $user = $request->user()->load('profile');
        $session->load(['planDay', 'exercises.exercise', 'exercises.sets', 'exercises.planItem']);

        $result = $session->autosave_state['result'] ?? [];
        $stored = $session->autosave_state ?? [];

        $prs = collect($stored['prs'] ?? [])->map(function (array $pr) use ($session) {
            $exercise = $session->exercises->firstWhere('exercise_id', $pr['exercise_id'])?->exercise;
            $best = collect($pr['records'])->firstWhere('type', 'est_1rm') ?? collect($pr['records'])->first();

            return [
                'name' => $exercise?->name ?? 'Exercise',
                'weight' => $pr['weight_kg'],
                'reps' => $pr['reps'],
                'type' => $best['type'] ?? 'est_1rm',
                'value' => $best['value'] ?? null,
                'previous' => $best['previous'] ?? null,
            ];
        })->unique('name')->values();

        $targets = ProgressionTarget::query()
            ->where('user_id', $user->id)
            ->whereIn('plan_day_exercise_id', $session->exercises->pluck('plan_day_exercise_id')->filter())
            ->get()->keyBy('plan_day_exercise_id');

        $exercises = $session->exercises->map(fn ($row) => [
            'name' => $row->exercise->name,
            'sets' => $row->sets->where('is_warmup', false)->sortBy('set_number')->values(),
            'target' => $targets->get($row->plan_day_exercise_id),
            'status' => $row->status,
        ]);

        return view('workout.summary', [
            'session' => $session,
            'exercises' => $exercises,
            'done' => $session->exercises->where('status', 'done')->count(),
            'total' => $session->exercises->count(),
            'prs' => $prs,
            'xpBreakdown' => $this->sessions->xpBreakdown($session),
            'levelUp' => ($result['level_after'] ?? 1) > ($result['level_before'] ?? 1),
            'levelAfter' => $result['level_after'] ?? $user->level,
            'streak' => $result['streak'] ?? $user->workout_streak,
            'badges' => Badge::query()->whereIn('id', $result['badge_ids'] ?? [])->get(),
            'xp' => $xp->progress($user->xp_total),
            'user' => $user,
        ]);
    }

    /** Pick which day to train when today is a rest day (or the user turned today off). */
    private function choose($user): View
    {
        $options = $this->plans->weekOverview($user)
            ->filter(fn ($e) => $e['is_training'] && in_array($e['status'], ['today', 'missed', 'in_progress'], true))
            ->values();

        return view('workout.choose', ['options' => $options]);
    }

    private function parseDate(mixed $value, string $tz): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
