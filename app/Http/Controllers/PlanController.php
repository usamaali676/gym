<?php

namespace App\Http\Controllers;

use App\Http\Requests\DayActionRequest;
use App\Models\Exercise;
use App\Models\PlanDay;
use App\Models\User;
use App\Services\PlanService;
use App\Services\ProgressiveOverloadService;
use App\Services\WorkoutSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly WorkoutSessionService $sessions,
        private readonly ProgressiveOverloadService $overload,
    ) {}

    /** Week timeline: every day of the plan with its status. */
    public function index(Request $request): View
    {
        $user = $request->user()->load('profile.plan');
        $anchor = $this->parseDate($request->query('week'), $user->timezone()) ?? $user->localToday();

        $overview = $this->plans->weekOverview($user, $anchor);
        $monday = $overview->first()['date'];
        $thisMonday = $user->localToday()->startOfWeek();
        $plan = $this->plans->planFor($user);

        return view('plan.index', [
            'overview' => $overview,
            'plans' => $this->plans,
            'monday' => $monday,
            'sunday' => $monday->addDays(6),
            'weekNumber' => $this->plans->weekNumber($user, $monday),
            'prevWeek' => $monday->subWeek()->toDateString(),
            'nextWeek' => $monday->addWeek()->toDateString(),
            'isThisWeek' => $monday->equalTo($thisMonday),
            'progress' => $this->plans->weekProgress($user, $overview),
            'plan' => $plan,
            'restRules' => $this->restRules(),
            'user' => $user,
        ]);
    }

    /** One day of the plan: exercises, rest times and the day actions. */
    public function show(Request $request, int $day): View
    {
        $user = $request->user()->load('profile.plan');
        $planDay = $this->findDay($user, $day);

        $date = $this->parseDate($request->query('date'), $user->timezone())
            ?? $this->plans->dateForWeekday($user, $day);
        $date = $date->dayOfWeekIso === $day ? $date : $this->plans->dateForWeekday($user, $day, $date);

        $entry = $this->plans->weekOverview($user, $date)->firstWhere('date', $date);
        $today = $user->localToday();

        $session = $planDay->is_rest ? null : $this->sessions->sessionOn($user, $planDay, $date);
        $views = $this->plans->effectiveItems($user, $planDay);

        return view('plan.day', [
            'day' => $planDay,
            'date' => $date,
            'status' => $entry['status'] ?? 'upcoming',
            'session' => $session,
            'views' => $views,
            'targets' => $planDay->is_rest ? [] : $this->overload->forViews($user, $views),
            'minutes' => $this->plans->estimatedMinutes($planDay),
            'canAct' => ! $planDay->is_rest && $date->lte($today)
                && $date->gte($today->subDays(WorkoutSessionService::BACKFILL_DAYS)),
            'isFuture' => $date->gt($today),
            'plan' => $this->plans->planFor($user),
            'regions' => config('ironpulse.regions'),
            'user' => $user,
        ]);
    }

    public function complete(DayActionRequest $request, int $day): RedirectResponse
    {
        [$user, $planDay, $date] = $this->resolve($request, $day);

        $session = $this->sessions->completeManually($user, $planDay, $date);

        return redirect()->route('plan.day', ['day' => $day, 'date' => $date->toDateString()])
            ->with('status', $session->xp_earned > 0
                ? "{$planDay->label} {$planDay->focus} logged. +{$session->xp_earned} XP"
                : 'This day is already logged.');
    }

    public function skip(DayActionRequest $request, int $day): RedirectResponse
    {
        [$user, $planDay, $date] = $this->resolve($request, $day);

        $this->sessions->skip($user, $planDay, $date);

        return redirect()->route('plan.day', ['day' => $day, 'date' => $date->toDateString()])
            ->with('status', 'Day skipped. Rest up and come back stronger.');
    }

    public function unskip(DayActionRequest $request, int $day): RedirectResponse
    {
        [$user, $planDay, $date] = $this->resolve($request, $day);

        $this->sessions->unskip($user, $planDay, $date);

        return redirect()->route('plan.day', ['day' => $day, 'date' => $date->toDateString()])
            ->with('status', 'Skip removed.');
    }

    /** @return array{0:User,1:PlanDay,2:CarbonImmutable} */
    private function resolve(DayActionRequest $request, int $day): array
    {
        $user = $request->user()->load('profile.plan');
        $planDay = $this->findDay($user, $day);
        $date = $this->parseDate($request->validated('date'), $user->timezone())
            ?? $this->plans->dateForWeekday($user, $day);

        return [$user, $planDay, $date];
    }

    private function findDay($user, int $isoWeekday): PlanDay
    {
        abort_unless($isoWeekday >= 1 && $isoWeekday <= 7, 404);

        $plan = $this->plans->planFor($user);
        abort_unless($plan, 404);

        return $this->plans->days($plan)->get($isoWeekday) ?? abort(404);
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

    /** Min/max rest per mechanic, read from the plan's exercises (never hard-coded in the view). */
    private function restRules(): array
    {
        return Exercise::query()
            ->where('kind', 'plan')
            ->selectRaw('mechanic, MIN(rest_min_seconds) as min_s, MAX(rest_max_seconds) as max_s')
            ->groupBy('mechanic')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->mechanic->value => ['min' => (int) $row->min_s, 'max' => (int) $row->max_s]])
            ->all();
    }
}
