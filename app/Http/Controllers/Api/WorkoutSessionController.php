<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogSetRequest;
use App\Http\Requests\SessionStateRequest;
use App\Http\Requests\SwapRequest;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\WorkoutSession;
use App\Services\SwapService;
use App\Services\WorkoutPageData;
use App\Services\WorkoutSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WorkoutSessionController extends Controller
{
    public function __construct(private readonly WorkoutSessionService $sessions) {}

    public function state(SessionStateRequest $request, WorkoutSession $session): Response
    {
        Gate::authorize('update', $session);

        $this->sessions->saveClientState($session, $request->validated('state'));

        return response()->noContent();
    }

    public function logSet(LogSetRequest $request, WorkoutSession $session, WorkoutPageData $data): JsonResponse
    {
        Gate::authorize('update', $session);

        $result = $this->sessions->logSet($request->user(), $session, $request->validated());

        return response()->json([
            'set' => $data->set($result['set']),
            'isPr' => $result['prs'] !== [],
            'prs' => $result['prs'],
            'xp' => $result['xp'],
            'totals' => $result['totals'],
        ], 201);
    }

    public function deleteSet(Request $request, WorkoutSession $session, SessionSet $set): JsonResponse
    {
        Gate::authorize('update', $session);

        $this->sessions->deleteSet($request->user(), $session, $set);

        return response()->json(['ok' => true]);
    }

    public function alternatives(Request $request, WorkoutSession $session, SessionExercise $exercise, SwapService $swaps, WorkoutPageData $data): JsonResponse
    {
        Gate::authorize('update', $session);
        abort_unless($exercise->workout_session_id === $session->id && $exercise->planItem, 404);

        return response()->json([
            'alternatives' => $data->alternatives($swaps->alternativesFor($request->user()->load('profile'), $exercise->planItem)),
        ]);
    }

    public function swap(SwapRequest $request, WorkoutSession $session, SessionExercise $exercise, WorkoutPageData $data): JsonResponse
    {
        Gate::authorize('update', $session);

        $row = $this->sessions->swapExercise($request->user()->load('profile'), $session, $exercise, (int) $request->validated('exercise_id'));
        $row->load(['exercise.postureRules', 'originalExercise', 'planItem.exercise', 'sets']);

        return response()->json(['exercise' => $data->exercise($request->user(), $session, $row)]);
    }

    public function skipExercise(WorkoutSession $session, SessionExercise $exercise): JsonResponse
    {
        Gate::authorize('update', $session);

        $this->sessions->skipExercise($session, $exercise);

        return response()->json(['ok' => true]);
    }

    public function finish(Request $request, WorkoutSession $session): JsonResponse
    {
        Gate::authorize('update', $session);
        $validated = $request->validate(['active_seconds' => ['nullable', 'integer', 'between:0,21600']]);

        $this->sessions->finish($request->user(), $session, $validated['active_seconds'] ?? null);

        return response()->json(['ok' => true, 'summary' => route('workout.summary', $session)]);
    }

    public function abandon(WorkoutSession $session): JsonResponse
    {
        Gate::authorize('update', $session);

        $this->sessions->abandon($session);

        return response()->json(['ok' => true, 'redirect' => route('plan.index')]);
    }
}
