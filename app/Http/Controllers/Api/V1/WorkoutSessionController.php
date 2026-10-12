<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SessionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\ParsesDates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LogSetRequest;
use App\Http\Requests\Api\V1\SessionStateRequest;
use App\Http\Resources\V1\WorkoutSessionResource;
use App\Models\Badge;
use App\Models\ProgressionTarget;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\WorkoutSession;
use App\Services\PlanService;
use App\Services\SwapService;
use App\Services\WorkoutSessionService;
use App\Services\XpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Starting, running and finishing workouts. Every id in a URL is looked up among the caller's own sessions,
 * so another member's session is simply "not found".
 */
class WorkoutSessionController extends Controller
{
    use ParsesDates;

    private const WITH = [
        'planDay', 'exercises.exercise.postureRules', 'exercises.originalExercise', 'exercises.planItem.exercise', 'exercises.sets',
    ];

    public function __construct(
        private readonly WorkoutSessionService $sessions,
        private readonly PlanService $plans,
    ) {}

    /** Start today's (or a chosen day's) workout, or resume the one already open for it. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);
        $user = $request->user()->load('profile.plan');
        $today = $user->localToday();

        $weekday = $data['weekday'] ?? null;
        if ($weekday === null) {
            $todayDay = $this->plans->dayFor($user, $today);
            if (! $todayDay || ! $this->plans->isTrainingDay($user, $today)) {
                throw new ApiException('no_workout_today', 'Today is not a training day. Choose a day to train.', 422);
            }
            $weekday = $todayDay->day_of_week;
            $date = $today;
        } else {
            $date = $request->filled('date') ? $this->dateParam($request, $user) : $this->plans->dateForWeekday($user, $weekday);
        }

        $plan = $this->plans->planFor($user) ?? throw ApiException::notFound('No program selected.');
        $day = $this->plans->days($plan)->get($weekday) ?? throw ApiException::notFound('That day is not part of the program.');

        if (($open = $this->sessions->findOpen($user)) && ! ($open->plan_day_id === $day->id && $open->scheduled_for->toDateString() === $date->toDateString())) {
            throw ApiException::conflict('session_in_progress', 'Finish or abandon your workout in progress first.', ['session_id' => $open->id]);
        }

        $existing = $this->sessions->sessionOn($user, $day, $date);
        $reused = $existing && ($existing->isOpen() || $existing->status === SessionStatus::Completed);
        $session = $this->sessions->start($user, $day, $date, $data['client_uuid'] ?? null);

        return $this->payload($request, $session, $reused ? 200 : 201);
    }

    /** The unfinished workout, if any (so the app can offer to resume it after a restart). */
    public function active(Request $request): JsonResponse
    {
        $open = $this->sessions->findOpen($request->user());

        return $open ? $this->payload($request, $open) : response()->json(['data' => null]);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        return $this->payload($request, $this->mine($request, $session));
    }

    /** Finished and skipped workouts, newest first. */
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'status' => ['nullable', Rule::in(['completed', 'skipped', 'abandoned'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = WorkoutSession::query()->where('user_id', $request->user()->id)
            ->whereIn('status', [$params['status'] ?? 'completed'])
            ->with('planDay')->withCount(['exercises as exercises_done' => fn ($q) => $q->where('status', 'done')])
            ->orderByDesc('scheduled_for')->orderByDesc('id')
            ->cursorPaginate($params['per_page'] ?? config('ironpulse.mobile.page_size.default'));

        return response()->json([
            'data' => collect($page->items())->map(fn (WorkoutSession $s) => [
                'id' => $s->id,
                'title' => $s->planDay?->title() ?? 'Workout',
                'status' => $s->status->value,
                'scheduled_for' => $s->scheduled_for->toDateString(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'active_seconds' => (int) $s->active_seconds,
                'volume_kg' => $s->volume_kg,
                'sets_completed' => (int) $s->sets_completed,
                'xp_earned' => (int) $s->xp_earned,
                'exercises_done' => (int) $s->exercises_done,
            ])->values()->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()],
        ]);
    }

    /** Results of a completed workout: totals, XP breakdown, personal records, level, streak and badges earned. */
    public function summary(Request $request, int $session, XpService $xp): JsonResponse
    {
        $workout = $this->mine($request, $session);
        if ($workout->status !== SessionStatus::Completed) {
            throw ApiException::conflict('session_not_completed', 'This workout has not been finished.');
        }
        $workout->load(['planDay', 'exercises.exercise', 'exercises.sets']);

        $user = $request->user();
        $stored = $workout->autosave_state ?? [];
        $result = $stored['result'] ?? [];

        $prs = collect($stored['prs'] ?? [])->map(function (array $pr) use ($workout) {
            $exercise = $workout->exercises->firstWhere('exercise_id', $pr['exercise_id'])?->exercise;
            $best = collect($pr['records'])->firstWhere('type', 'est_1rm') ?? collect($pr['records'])->first();

            return ['exercise' => $exercise?->name ?? 'Exercise', 'weight_kg' => $pr['weight_kg'], 'reps' => $pr['reps'], 'type' => $best['type'] ?? 'est_1rm', 'value' => $best['value'] ?? null, 'previous' => $best['previous'] ?? null];
        })->unique('exercise')->values()->all();

        $targets = ProgressionTarget::query()->where('user_id', $user->id)
            ->whereIn('plan_day_exercise_id', $workout->exercises->pluck('plan_day_exercise_id')->filter())->get()->keyBy('plan_day_exercise_id');

        return response()->json(['data' => [
            'session' => [
                'id' => $workout->id, 'title' => $workout->planDay?->title() ?? 'Workout', 'scheduled_for' => $workout->scheduled_for->toDateString(),
                'active_seconds' => (int) $workout->active_seconds, 'volume_kg' => $workout->volume_kg, 'sets_completed' => (int) $workout->sets_completed, 'xp_earned' => (int) $workout->xp_earned,
            ],
            'exercises_done' => $workout->exercises->where('status', 'done')->count(),
            'exercises_total' => $workout->exercises->count(),
            'xp_breakdown' => $this->sessions->xpBreakdown($workout),
            'personal_records' => $prs,
            'level_up' => ($result['level_after'] ?? 1) > ($result['level_before'] ?? 1),
            'level' => $result['level_after'] ?? $user->level,
            'streak' => $result['streak'] ?? $user->workout_streak,
            'xp' => $xp->progress($user->xp_total),
            'badges' => Badge::query()->whereIn('id', $result['badge_ids'] ?? [])->get()->map(fn (Badge $b) => ['slug' => $b->slug, 'name' => $b->name, 'icon' => $b->icon, 'tier' => $b->tier, 'xp_reward' => (int) $b->xp_reward])->all(),
            'exercises' => $workout->exercises->map(fn (SessionExercise $row) => [
                'name' => $row->exercise->name,
                'status' => $row->status,
                'sets' => $row->sets->where('is_warmup', false)->sortBy('set_number')->map(fn (SessionSet $s) => WorkoutSessionResource::set($s))->values()->all(),
                'next_target' => ($t = $targets->get($row->plan_day_exercise_id)) ? ['weight_kg' => $t->target_weight_kg !== null ? (float) $t->target_weight_kg : null, 'reason' => $t->reason] : null,
            ])->values()->all(),
        ]]);
    }

    /**
     * Save the resume state. A save older than the stored one (a device that was offline and replays late) is
     * ignored, so a stale phone cannot rewind a newer session.
     */
    public function state(SessionStateRequest $request, int $session): JsonResponse
    {
        $workout = $this->mine($request, $session);
        $incoming = $request->validated('state');

        $stored = $workout->autosave_state['client'] ?? null;
        $storedAt = isset($stored['saved_at']) ? strtotime($stored['saved_at']) : null;
        $incomingAt = isset($incoming['saved_at']) ? strtotime($incoming['saved_at']) : null;

        if ($storedAt && $incomingAt && $incomingAt < $storedAt) {
            return response()->json(['data' => ['applied' => false, 'client_state' => $stored]]);
        }

        $this->sessions->saveClientState($workout, $incoming);

        return response()->json(['data' => ['applied' => true, 'client_state' => $incoming]]);
    }

    public function logSet(LogSetRequest $request, int $session): JsonResponse
    {
        $workout = $this->mine($request, $session);
        $row = $workout->exercises()->whereKey($request->validated('session_exercise_id'))->first()
            ?? throw ApiException::notFound('Exercise not found in this workout.');

        $result = $this->sessions->logSet($request->user(), $workout, $request->validated());

        return response()->json(['data' => [
            'set' => WorkoutSessionResource::set($result['set']),
            'exercise_status' => $row->fresh()->status,
            'is_pr' => $result['prs'] !== [],
            'personal_records' => $result['prs'],
            'xp' => $result['xp'],
            'totals' => $result['totals'],
        ]], 201);
    }

    public function deleteSet(Request $request, int $session, int $set): JsonResponse
    {
        $workout = $this->mine($request, $session);
        $row = SessionSet::query()->where('workout_session_id', $workout->id)->whereKey($set)->first() ?? throw ApiException::notFound('Set not found.');

        $this->sessions->deleteSet($request->user(), $workout, $row);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function alternatives(Request $request, int $session, int $exercise, SwapService $swaps): JsonResponse
    {
        $row = $this->exerciseRow($request, $session, $exercise);
        abort_unless($row->planItem, 404);

        return response()->json(['data' => $swaps->alternativesFor($request->user()->load('profile'), $row->planItem)->map(fn ($e) => [
            'id' => $e->id, 'slug' => $e->slug, 'name' => $e->name, 'kind' => $e->kind->value, 'mechanic' => $e->mechanic->value, 'difficulty' => $e->difficulty->value, 'equipment' => $e->equipment ?? [],
        ])->values()->all()]);
    }

    public function swap(Request $request, int $session, int $exercise): JsonResponse
    {
        $row = $this->exerciseRow($request, $session, $exercise);
        $data = $request->validate(['exercise_id' => ['required', 'integer', 'exists:exercises,id']]);

        $this->sessions->swapExercise($request->user()->load('profile'), $this->mine($request, $session), $row, (int) $data['exercise_id']);

        return $this->payload($request, $this->mine($request, $session));
    }

    public function skipExercise(Request $request, int $session, int $exercise): JsonResponse
    {
        $this->sessions->skipExercise($this->mine($request, $session), $this->exerciseRow($request, $session, $exercise));

        return response()->json(['data' => ['ok' => true]]);
    }

    public function finish(Request $request, int $session): JsonResponse
    {
        $data = $request->validate(['active_seconds' => ['nullable', 'integer', 'between:0,21600']]);
        $workout = $this->mine($request, $session);

        $this->sessions->finish($request->user(), $workout, $data['active_seconds'] ?? null);

        return response()->json(['data' => ['ok' => true, 'session_id' => $workout->id, 'status' => 'completed']]);
    }

    public function abandon(Request $request, int $session): JsonResponse
    {
        $this->sessions->abandon($this->mine($request, $session));

        return response()->json(['data' => ['ok' => true, 'status' => 'abandoned']]);
    }

    // ------------------------------------------------------------------ helpers

    private function mine(Request $request, int $id): WorkoutSession
    {
        return WorkoutSession::query()->where('user_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('Workout not found.');
    }

    private function exerciseRow(Request $request, int $session, int $exercise): SessionExercise
    {
        $workout = $this->mine($request, $session);

        return $workout->exercises()->whereKey($exercise)->with('planItem')->first() ?? throw ApiException::notFound('Exercise not found in this workout.');
    }

    private function payload(Request $request, WorkoutSession $session, int $status = 200): JsonResponse
    {
        $request->user()->load('profile'); // WorkoutPageData reads the member's preferences from it
        $session->load(self::WITH);

        return (new WorkoutSessionResource($session))->response($request)->setStatusCode($status);
    }
}
