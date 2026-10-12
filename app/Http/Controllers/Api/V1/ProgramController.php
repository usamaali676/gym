<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProgramDetailResource;
use App\Http\Resources\V1\ProgramResource;
use App\Models\Plan;
use App\Services\Membership\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProgramController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** Published programs (premium ones appear as locked cards). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->user()->load('profile'); // `is_selected` reads the member's current program

        return ProgramResource::collection(
            Plan::query()->published()->with(['days', 'phases'])->orderByDesc('is_default')->orderBy('name')->get()
        );
    }

    public function show(Request $request, int $plan): ProgramDetailResource
    {
        $current = $request->user()->profile?->plan_id;
        $program = Plan::query()->with(['days.items.exercise', 'phases'])
            ->where(fn ($q) => $q->where('status', 'published')->orWhere(fn ($w) => $w->where('id', $current)->where('status', 'archived')))
            ->find($plan) ?? throw ApiException::notFound('Program not found.');

        $this->authorizeProgram($request, $program);

        return new ProgramDetailResource($program);
    }

    /** Make a program the member's current one. Past workouts stay attached to what was actually done. */
    public function select(Request $request): JsonResponse
    {
        $data = $request->validate(['program_id' => ['required', 'integer']]);
        $program = Plan::query()->published()->find($data['program_id']) ?? throw ApiException::notFound('Program not found.');

        $this->authorizeProgram($request, $program);

        $profile = $request->user()->profile ?? throw ApiException::onboardingRequired();
        if ($profile->plan_id !== $program->id) {
            $profile->forceFill(['plan_id' => $program->id, 'plan_started_on' => $request->user()->localToday()->toDateString()])->save();
        }

        return response()->json(['data' => ['program_id' => $program->id, 'plan_started_on' => $profile->plan_started_on?->toDateString()]]);
    }

    private function authorizeProgram(Request $request, Plan $program): void
    {
        if ($program->required_entitlement) {
            $this->entitlements->authorize($request->user(), $program->required_entitlement);
        }
    }
}
