<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\ParsesDates;
use App\Http\Controllers\Controller;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Models\User;
use App\Services\PlanService;
use App\Services\ProgressiveOverloadService;
use App\Services\SwapService;
use App\Services\WorkoutSessionService;
use App\Support\PlanItemView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The member's weekly plan: what is scheduled, what the day contains, and marking days done / skipped. */
class PlanController extends Controller
{
    use ParsesDates;

    public function __construct(
        private readonly PlanService $plans,
        private readonly WorkoutSessionService $sessions,
        private readonly ProgressiveOverloadService $overload,
        private readonly SwapService $swaps,
    ) {}

    public function week(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $request->user()->load('profile.plan');
        $anchor = $this->dateParam($request, $user);

        $overview = $this->plans->weekOverview($user, $anchor);
        $monday = $overview->first()['date'];

        return response()->json(['data' => [
            'program' => $this->plans->planFor($user)?->only(['id', 'name']),
            'week_number' => $this->plans->weekNumber($user, $monday),
            'monday' => $monday->toDateString(),
            'sunday' => $monday->addDays(6)->toDateString(),
            'is_this_week' => $monday->equalTo($user->localToday()->startOfWeek()),
            'progress' => $this->plans->weekProgress($user, $overview),
            'days' => $overview->map(fn (array $e) => [
                'date' => $e['date']->toDateString(),
                'weekday' => $e['date']->dayOfWeekIso,
                'status' => $e['status'],
                'is_today' => $e['is_today'],
                'is_training' => $e['is_training'],
                'day' => $e['day'] ? $this->dayHeader($e['day']) : null,
                'session' => $e['session'] ? ['id' => $e['session']->id, 'status' => $e['session']->status->value] : null,
            ])->values()->all(),
        ]]);
    }

    public function day(Request $request, int $weekday): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $request->user()->load('profile.plan');
        $day = $this->findDay($user, $weekday);

        $anchor = $request->filled('date') ? $this->dateParam($request, $user) : null;
        $date = $anchor && $anchor->dayOfWeekIso === $weekday ? $anchor : $this->plans->dateForWeekday($user, $weekday, $anchor);

        $entry = $this->plans->weekOverview($user, $date)->firstWhere('date', $date);
        $today = $user->localToday();
        $session = $day->is_rest ? null : $this->sessions->sessionOn($user, $day, $date);
        $views = $this->plans->effectiveItems($user, $day);
        $targets = $day->is_rest ? [] : $this->overload->forViews($user, $views);

        return response()->json(['data' => [
            'date' => $date->toDateString(),
            'weekday' => $weekday,
            'week_number' => $this->plans->weekNumber($user, $date),
            'status' => $entry['status'] ?? 'upcoming',
            'estimated_minutes' => $this->plans->estimatedMinutes($day),
            'can_act' => ! $day->is_rest && $date->lte($today) && $date->gte($today->subDays(WorkoutSessionService::BACKFILL_DAYS)),
            'is_future' => $date->gt($today),
            'day' => $this->dayHeader($day) + ['description' => $day->description, 'recovery_note' => $day->recovery_note],
            'session' => $session ? ['id' => $session->id, 'status' => $session->status->value, 'xp_earned' => (int) $session->xp_earned] : null,
            'exercises' => $views->map(fn (PlanItemView $v) => [
                'item_id' => $v->item->id,
                'position' => $v->item->position,
                'name' => $v->name(),
                'swapped' => $v->swapped(),
                'permanent_swap' => (bool) $v->swap?->is_permanent,
                'original_exercise_id' => $v->swapped() ? $v->original()->id : null,
                'exercise' => [
                    'id' => $v->exercise->id, 'slug' => $v->exercise->slug, 'name' => $v->exercise->name,
                    'muscle_group' => $v->exercise->muscle_group->value, 'equipment' => $v->exercise->equipment ?? [],
                    'is_timed' => (bool) $v->exercise->is_timed,
                ],
                'sets' => $v->item->sets,
                'reps_min' => $v->item->reps_min,
                'reps_max' => $v->item->reps_max,
                'reps_label' => $v->item->repsLabel(),
                'focus' => $v->item->focus,
                'notes' => $v->item->notes,
                'rest_seconds' => $v->restSeconds(),
                'rest_label' => $v->restLabel(),
                'target' => $targets[$v->item->id] ?? null,
            ])->values()->all(),
        ]]);
    }

    public function complete(Request $request, int $weekday): JsonResponse
    {
        [$user, $day, $date] = $this->resolve($request, $weekday);
        $session = $this->sessions->completeManually($user, $day, $date);

        return response()->json(['data' => ['status' => 'completed', 'session_id' => $session->id, 'xp_earned' => (int) $session->xp_earned]]);
    }

    public function skip(Request $request, int $weekday): JsonResponse
    {
        [$user, $day, $date] = $this->resolve($request, $weekday);
        $this->sessions->skip($user, $day, $date);

        return response()->json(['data' => ['status' => 'skipped']]);
    }

    public function unskip(Request $request, int $weekday): JsonResponse
    {
        [$user, $day, $date] = $this->resolve($request, $weekday);
        $this->sessions->unskip($user, $day, $date);

        return response()->json(['data' => ['status' => 'unskipped']]);
    }

    // ------------------------------------------------------------------ swaps

    public function alternatives(Request $request, int $item): JsonResponse
    {
        $row = $this->followedItem($request, $item);
        $user = $request->user()->load('profile');

        return response()->json(['data' => $this->swaps->alternativesFor($user, $row)->map(fn ($e) => [
            'id' => $e->id, 'slug' => $e->slug, 'name' => $e->name, 'kind' => $e->kind->value,
            'mechanic' => $e->mechanic->value, 'difficulty' => $e->difficulty->value, 'equipment' => $e->equipment ?? [],
        ])->values()->all()]);
    }

    public function swap(Request $request, int $item): JsonResponse
    {
        $row = $this->followedItem($request, $item);
        $data = $request->validate([
            'exercise_id' => ['required', 'integer', Rule::exists('exercises', 'id')->where('status', 'published')],
            'permanent' => ['sometimes', 'boolean'],
        ]);

        $swap = $this->swaps->swap($request->user()->load('profile'), $row, (int) $data['exercise_id'], $request->boolean('permanent'));

        return response()->json(['data' => ['item_id' => $row->id, 'exercise_id' => $swap->replacement_exercise_id, 'permanent' => (bool) $swap->is_permanent]], 201);
    }

    public function unswap(Request $request, int $item): JsonResponse
    {
        $row = $this->followedItem($request, $item);
        $this->swaps->clear($request->user(), $row);

        return response()->json(['data' => ['ok' => true]]);
    }

    // ------------------------------------------------------------------ helpers

    /** Only rows of the program the member follows; anything else behaves as not found. */
    private function followedItem(Request $request, int $id): PlanDayExercise
    {
        $planId = $request->user()->profile?->plan_id;

        return PlanDayExercise::query()->whereKey($id)
            ->whereHas('day', fn ($q) => $q->where('plan_id', $planId))->first()
            ?? throw ApiException::notFound('Plan exercise not found.');
    }

    /** @return array{0: User, 1: PlanDay, 2: CarbonImmutable} */
    private function resolve(Request $request, int $weekday): array
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $request->user()->load('profile.plan');
        $day = $this->findDay($user, $weekday);
        $date = $request->filled('date') ? $this->dateParam($request, $user) : $this->plans->dateForWeekday($user, $weekday);

        return [$user, $day, $date];
    }

    private function findDay(User $user, int $weekday): PlanDay
    {
        if ($weekday < 1 || $weekday > 7) {
            throw ApiException::notFound('Unknown weekday.');
        }
        $plan = $this->plans->planFor($user) ?? throw ApiException::notFound('No program selected.');

        return $this->plans->days($plan)->get($weekday) ?? throw ApiException::notFound('That day is not part of the program.');
    }

    /** @return array<string, mixed> */
    private function dayHeader(PlanDay $day): array
    {
        return [
            'id' => $day->id, 'weekday' => $day->day_of_week, 'name' => $day->name, 'slug' => $day->slug,
            'session_type' => $day->session_type->value, 'label' => $day->label, 'focus' => $day->focus,
            'title' => $day->title(), 'is_rest' => (bool) $day->is_rest,
        ];
    }
}
