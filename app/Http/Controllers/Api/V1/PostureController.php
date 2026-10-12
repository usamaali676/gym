<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostureSessionRequest;
use App\Models\Exercise;
use App\Models\PostureRule;
use App\Services\PostureSessionService;
use App\Services\XpService;
use App\Support\BadgePresenter;
use Illuminate\Http\JsonResponse;

/**
 * The camera coach's server side: which exercises it can judge, the versioned rule to apply, and where the numeric
 * summary of a set is saved. Camera frames never reach the server; only counts and scores do.
 */
class PostureController extends Controller
{
    /** Exercises that have an active rule: this is the full list of what the coach can analyse. */
    public function exercises(): JsonResponse
    {
        $rules = PostureRule::query()->where('is_active', true)->with('exercise')->get()
            ->filter(fn (PostureRule $r) => $r->exercise?->status === 'published')
            ->groupBy('exercise_id')->map(fn ($g) => $g->sortByDesc('version')->first())->values();

        return response()->json(['data' => $rules->map(fn (PostureRule $r) => [
            'exercise' => ['id' => $r->exercise->id, 'slug' => $r->exercise->slug, 'name' => $r->exercise->name, 'muscle_group' => $r->exercise->muscle_group->value],
            'rule_id' => $r->id,
            'rule_version' => $r->version,
            'tier' => $r->tier->value,
            'camera_view' => $r->camera_view->value,
        ])->sortBy('exercise.name')->values()->all()]);
    }

    /** The JSON rule (schema 1) the app's engine evaluates, with its on-screen guidance text. */
    public function rule(string $exercise): JsonResponse
    {
        $found = Exercise::query()->published()->where('slug', $exercise)->first() ?? throw ApiException::notFound('Exercise not found.');
        $rule = PostureRule::query()->where('exercise_id', $found->id)->where('is_active', true)->orderByDesc('version')->first()
            ?? throw ApiException::notFound('The posture coach does not support this exercise yet.');

        return response()->json(['data' => [
            'exercise' => ['id' => $found->id, 'slug' => $found->slug, 'name' => $found->name],
            'rule_id' => $rule->id,
            'rule_version' => $rule->version,
            'tier' => $rule->tier->value,
            'camera_view' => $rule->camera_view->value,
            'config' => $rule->config,
            'guidance' => $rule->guidance,
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ]]);
    }

    public function store(StorePostureSessionRequest $request, PostureSessionService $sessions, XpService $xp): JsonResponse
    {
        $user = $request->user();
        $result = $sessions->record($user, $request->validated());
        $user->refresh();

        return response()->json(['data' => [
            'id' => $result['session']->id,
            'created' => $result['created'],
            'xp' => $result['xp'],
            'badges' => BadgePresenter::many($result['badges']),
            'level' => $xp->progress($user->xp_total) + ['xp_total' => $user->xp_total],
        ]], $result['created'] ? 201 : 200);
    }
}
