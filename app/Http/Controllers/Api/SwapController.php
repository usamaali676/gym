<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SwapRequest;
use App\Models\PlanDayExercise;
use App\Services\SwapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SwapController extends Controller
{
    public function __construct(private readonly SwapService $swaps) {}

    public function index(Request $request, PlanDayExercise $item): JsonResponse
    {
        Gate::authorize('swap', $item);
        $user = $request->user()->load('profile');

        return response()->json([
            'alternatives' => $this->swaps->alternativesFor($user, $item)->map(fn ($e) => [
                'id' => $e->id,
                'slug' => $e->slug,
                'name' => $e->name,
                'kind' => $e->kind->value,
                'mechanic' => $e->mechanic->label(),
                'difficulty' => $e->difficulty->label(),
                'equipment' => $e->equipment,
            ])->values(),
        ]);
    }

    public function store(SwapRequest $request, PlanDayExercise $item): JsonResponse
    {
        Gate::authorize('swap', $item);

        $swap = $this->swaps->swap(
            $request->user()->load('profile'),
            $item,
            (int) $request->validated('exercise_id'),
            (bool) $request->boolean('permanent'),
        );

        return response()->json(['ok' => true, 'permanent' => $swap->is_permanent], 201);
    }

    public function destroy(Request $request, PlanDayExercise $item): JsonResponse
    {
        Gate::authorize('swap', $item);

        $this->swaps->clear($request->user(), $item);

        return response()->json(['ok' => true]);
    }
}
