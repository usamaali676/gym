<?php

namespace App\Http\Controllers;

use App\Enums\PostureTier;
use App\Models\Exercise;
use App\Models\PostureRule;
use App\Models\PostureSession;
use App\Models\WorkoutSession;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PostureLabController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    /** Every exercise that has a posture rule, today's lifts first, plus the user's recent sets. */
    public function index(Request $request): View
    {
        $user = $request->user()->load('profile.plan');

        $rules = PostureRule::query()
            ->where('is_active', true)
            ->with('exercise')
            ->get()
            ->filter(fn (PostureRule $r) => $r->exercise?->is_active)
            ->groupBy('exercise_id')
            ->map(fn (Collection $group) => $group->sortByDesc('version')->first())
            ->sortBy(fn (PostureRule $r) => $r->exercise->name)
            ->values();

        $todayIds = collect();
        $day = $this->plans->dayFor($user, $user->localToday());
        if ($day && ! $day->is_rest) {
            $todayIds = $this->plans->effectiveItems($user, $day)->map(fn ($v) => $v->exercise->id);
        }

        $since = now()->subDays(30);
        $recent = PostureSession::query()->where('user_id', $user->id)->with('exercise')->latest('started_at')->limit(6)->get();
        $month = PostureSession::query()->where('user_id', $user->id)->where('started_at', '>=', $since);

        return view('posture.index', [
            'today' => $rules->filter(fn (PostureRule $r) => $todayIds->contains($r->exercise_id))->values(),
            'tiers' => collect(PostureTier::cases())->mapWithKeys(fn (PostureTier $t) => [
                $t->value => $rules->filter(fn (PostureRule $r) => $r->tier === $t)->values(),
            ]),
            'recent' => $recent,
            'stats' => [
                'sets' => (clone $month)->count(),
                'good_reps' => (int) (clone $month)->sum('good_reps'),
                'total_reps' => (int) (clone $month)->sum('total_reps'),
                'avg_score' => (int) round((clone $month)->avg('form_score_avg') ?? 0),
            ],
            'assetsReady' => $this->assetsReady(),
            'user' => $user,
        ]);
    }

    public function show(Request $request, Exercise $exercise): View
    {
        abort_unless($exercise->is_active, 404);

        $user = $request->user()->load('profile');
        $exercise->load('postureRules');
        $rule = $exercise->activePostureRule();
        abort_unless($rule, 404);

        $workout = null;
        if ($request->filled('session')) {
            $workout = WorkoutSession::query()->where('user_id', $user->id)->whereKey($request->integer('session'))->first();
        }

        $history = PostureSession::query()
            ->where(['user_id' => $user->id, 'exercise_id' => $exercise->id])
            ->latest('started_at')->limit(5)->get();

        return view('posture.show', [
            'exercise' => $exercise,
            'rule' => $rule,
            'history' => $history,
            'workout' => $workout,
            'assetsReady' => $this->assetsReady(),
            'lab' => [
                'exercise' => ['id' => $exercise->id, 'slug' => $exercise->slug, 'name' => $exercise->name, 'timed' => (bool) $exercise->is_timed || ($rule->config['rep']['hold'] ?? false)],
                'rule' => [
                    'id' => $rule->id,
                    'version' => $rule->version,
                    'tier' => $rule->tier->value,
                    'camera_view' => $rule->camera_view->value,
                    'config' => $rule->config,
                    'guidance' => $rule->guidance,
                ],
                'assets' => [
                    'ready' => $this->assetsReady(),
                    'wasm' => url(config('ironpulse.posture.assets_url').'/wasm'),
                    'model' => url(config('ironpulse.posture.assets_url').'/models/pose_landmarker_lite.task'),
                    'variant' => 'lite',
                ],
                'urls' => [
                    'save' => route('api.posture.store'),
                    'library' => route('library.show', $exercise),
                    'lab' => route('posture.index'),
                    'workout' => $workout?->status->value === 'in_progress' ? route('workout.play', $workout) : null,
                ],
                'workoutSessionId' => $workout?->id,
                'prefs' => [
                    'sound' => (bool) $user->profile?->sound_enabled,
                    'voice' => (bool) $user->profile?->voice_coach,
                ],
            ],
        ]);
    }

    private function assetsReady(): bool
    {
        $dir = config('ironpulse.posture.assets_dir');

        return is_file($dir.'/models/pose_landmarker_lite.task') && is_file($dir.'/wasm/vision_wasm_internal.wasm');
    }
}
