<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CameraView;
use App\Enums\PostureTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PostureRuleRequest;
use App\Models\Exercise;
use App\Models\PostureRule;
use App\Services\PostureRuleValidator;
use App\Support\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Posture rules are versioned: an edit never changes a row that sessions already point at, it writes version N+1
 * and retires the old one. "Restore" copies an old version forward as a new one.
 */
class PostureRuleController extends Controller
{
    public function index(): View
    {
        $rules = PostureRule::query()->with('exercise')->get()->groupBy('exercise_id')
            ->map(fn ($group) => ['current' => $group->where('is_active', true)->sortByDesc('version')->first() ?? $group->sortByDesc('version')->first(), 'versions' => $group->count()])
            ->sortBy(fn ($row) => $row['current']->exercise->name)->values();

        return view('admin.posture.index', ['rows' => $rules]);
    }

    public function edit(Exercise $exercise): View
    {
        $versions = $exercise->postureRules()->orderByDesc('version')->get();
        $current = $versions->where('is_active', true)->first() ?? $versions->first();
        abort_unless($current, 404);

        return view('admin.posture.edit', [
            'exercise' => $exercise,
            'rule' => $current,
            'versions' => $versions,
            'tiers' => PostureTier::cases(),
            'views' => CameraView::cases(),
            'configJson' => old('config_json', json_encode($current->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'guidanceJson' => old('guidance_json', $current->guidance ? json_encode($current->guidance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ''),
        ]);
    }

    public function update(PostureRuleRequest $request, Exercise $exercise): RedirectResponse
    {
        $new = $this->newVersion($exercise, $request->input('tier'), $request->input('camera_view'), $request->config, $request->guidance, $request->user()->id);

        return redirect()->route('admin.posture.edit', $exercise)->with('status', "Saved as version {$new->version}. The lab uses it immediately; earlier sessions keep their old version.");
    }

    public function restore(Request $request, Exercise $exercise, PostureRule $rule): RedirectResponse
    {
        abort_unless($rule->exercise_id === $exercise->id, 404);
        $new = $this->newVersion($exercise, $rule->tier->value, $rule->camera_view->value, $rule->config, $rule->guidance, $request->user()->id);

        return redirect()->route('admin.posture.edit', $exercise)->with('status', "Version {$rule->version} restored as version {$new->version}.");
    }

    /** Server-side check for the editor's "Check" button. Same validator as saving. */
    public function validateConfig(Request $request, PostureRuleValidator $validator): JsonResponse
    {
        $config = json_decode((string) $request->input('config_json'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['ok' => false, 'errors' => ['Not valid JSON: '.json_last_error_msg().'.']]);
        }
        $errors = $validator->validate($config);

        return response()->json(['ok' => $errors === [], 'errors' => $errors]);
    }

    private function newVersion(Exercise $exercise, string $tier, string $view, array $config, ?array $guidance, int $adminId): PostureRule
    {
        return DB::transaction(function () use ($exercise, $tier, $view, $config, $guidance, $adminId) {
            $next = (int) PostureRule::where('exercise_id', $exercise->id)->max('version') + 1;
            PostureRule::where('exercise_id', $exercise->id)->update(['is_active' => false]);

            $rule = PostureRule::create([
                'exercise_id' => $exercise->id, 'tier' => $tier, 'camera_view' => $view, 'config' => $config, 'guidance' => $guidance,
                'version' => $next, 'is_active' => true, 'updated_by' => $adminId,
            ]);
            AdminAudit::record('posture.rule', ['exercise_id' => $exercise->id, 'version' => $next]);

            return $rule;
        });
    }
}
