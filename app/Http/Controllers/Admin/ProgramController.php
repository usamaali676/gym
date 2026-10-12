<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\ProgramPhase;
use App\Support\AdminAudit;
use App\Support\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Programs (training plans) as the apps see them: publishing state, premium gate, sprints / phases and copies.
 * The days and exercises of a program are edited in the existing plan editor (admin.plan.*).
 */
class ProgramController extends Controller
{
    public function index(): View
    {
        return view('admin.programs.index', [
            'programs' => Plan::query()->withCount(['phases', 'days as training_days' => fn ($q) => $q->where('is_rest', false)])->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    /** New program: blank, or a draft copy of an existing one (days and exercises included). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'copy_from' => ['nullable', 'integer', 'exists:plans,id'],
        ]);

        $slug = Str::slug($data['name']);
        for ($i = 2; Plan::where('slug', $slug)->exists(); $i++) {
            $slug = Str::slug($data['name']).'-'.$i;
        }

        $plan = DB::transaction(function () use ($data, $slug) {
            $source = isset($data['copy_from']) ? Plan::with('days.items')->find($data['copy_from']) : null;
            $plan = Plan::create([
                'slug' => $slug, 'name' => $data['name'], 'source' => $source?->source, 'version' => 1,
                'is_default' => false, 'rules' => $source?->rules, 'status' => 'draft', 'description' => $source?->description,
            ]);

            foreach ($source?->days ?? [] as $day) {
                $copy = PlanDay::create($day->only(['day_of_week', 'slug', 'name', 'session_type', 'label', 'focus', 'is_rest', 'description', 'recovery_note']) + ['plan_id' => $plan->id]);
                foreach ($day->items as $item) {
                    $copy->items()->create($item->only(['exercise_id', 'position', 'sets', 'reps_min', 'reps_max', 'focus', 'label_override', 'notes', 'rest_seconds_override']));
                }
            }

            return $plan;
        });
        AdminAudit::record('program.create', ['plan_id' => $plan->id, 'copied_from' => $data['copy_from'] ?? null]);

        return redirect()->route('admin.programs.edit', $plan)->with('status', 'Program created as a draft. Publish it when it is ready.');
    }

    public function edit(Plan $plan): View
    {
        return view('admin.programs.edit', [
            'plan' => $plan->load(['phases', 'days']),
            'gates' => [Entitlement::ProgramsPremium],
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'required_entitlement' => ['nullable', Rule::in([Entitlement::ProgramsPremium->value])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $makeDefault = $request->boolean('is_default');
        if (($makeDefault || $plan->is_default) && $data['status'] !== 'published') {
            return back()->withInput()->withErrors(['status' => 'The default program must be published.']);
        }
        if (($makeDefault || $plan->is_default) && ! empty($data['required_entitlement'])) {
            return back()->withInput()->withErrors(['required_entitlement' => 'The default program is what new members start on, so it cannot be premium.']);
        }
        if ($plan->is_default && ! $makeDefault) {
            return back()->withInput()->withErrors(['is_default' => 'Choose another program as the default instead of unsetting this one.']);
        }

        DB::transaction(function () use ($plan, $data, $makeDefault) {
            if ($makeDefault && ! $plan->is_default) {
                Plan::where('is_default', true)->get()->each->update(['is_default' => false]);
                $plan->is_default = true;
            }
            $plan->fill([
                'name' => $data['name'], 'description' => $data['description'] ?? null, 'status' => $data['status'],
                'required_entitlement' => $data['required_entitlement'] ?? null,
            ]);
            if ($data['status'] === 'published' && ! $plan->published_at) {
                $plan->published_at = now();
            }
            $plan->version = $plan->version + 1;
            $plan->save();
        });
        AdminAudit::record('program.update', ['plan_id' => $plan->id, 'status' => $data['status']]);

        return redirect()->route('admin.programs.edit', $plan)->with('status', 'Program saved. Apps pick up the change on their next refresh.');
    }

    public function storePhase(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->phaseData($request);
        $phase = $plan->phases()->create($data + ['sort_order' => (int) $plan->phases()->max('sort_order') + 1]);
        AdminAudit::record('program.phase.add', ['plan_id' => $plan->id, 'phase_id' => $phase->id]);

        return back()->with('status', 'Phase added.');
    }

    public function updatePhase(Request $request, ProgramPhase $phase): RedirectResponse
    {
        $phase->update($this->phaseData($request));
        AdminAudit::record('program.phase.update', ['phase_id' => $phase->id]);

        return back()->with('status', 'Phase saved.');
    }

    public function destroyPhase(ProgramPhase $phase): RedirectResponse
    {
        AdminAudit::record('program.phase.remove', ['phase_id' => $phase->id]);
        $phase->delete();

        return back()->with('status', 'Phase removed.');
    }

    /** @return array<string, mixed> */
    private function phaseData(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ProgramPhase::KINDS)],
            'name' => ['required', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'starts_week' => ['required', 'integer', 'between:1,520'],
            'ends_week' => ['nullable', 'integer', 'between:1,520', 'gte:starts_week'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);

        return $data + ['ends_week' => null];
    }
}
