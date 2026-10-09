<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SessionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanDayRequest;
use App\Http\Requests\Admin\PlanItemRequest;
use App\Models\Exercise;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Edits the default plan in place. Rows keep their ids, so history, progression targets and swaps follow the
 * exercise. Removing a row drops its progression targets (workout history keeps the exercise).
 */
class PlanController extends Controller
{
    public function index(): View
    {
        $plan = Plan::default()?->load(['days.items.exercise']);
        abort_unless($plan, 404);

        return view('admin.plan.index', ['plan' => $plan]);
    }

    public function edit(PlanDay $day): View
    {
        $day->load(['items.exercise', 'plan']);
        $taken = $day->items->pluck('exercise_id');

        return view('admin.plan.edit', [
            'day' => $day,
            'types' => SessionType::cases(),
            'available' => Exercise::query()->active()->whereNotIn('id', $taken)->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind', 'muscle_group']),
        ]);
    }

    public function update(PlanDayRequest $request, PlanDay $day): RedirectResponse
    {
        $day->update($request->validated());
        $this->touchPlan($day);
        AdminAudit::record('plan.day.update', ['plan_day_id' => $day->id]);

        return back()->with('status', 'Day saved.');
    }

    public function storeItem(PlanItemRequest $request, PlanDay $day): RedirectResponse
    {
        $position = (int) PlanDayExercise::where('plan_day_id', $day->id)->max('position') + 1;
        $item = $day->items()->create($request->validated() + ['position' => $position]);
        $this->touchPlan($day);
        AdminAudit::record('plan.item.add', ['plan_day_id' => $day->id, 'exercise_id' => $item->exercise_id]);

        return back()->with('status', 'Exercise added to the day.');
    }

    public function updateItem(PlanItemRequest $request, PlanDayExercise $item): RedirectResponse
    {
        $item->update($request->validated());
        $this->touchPlan($item->day);
        AdminAudit::record('plan.item.update', ['item_id' => $item->id]);

        return back()->with('status', 'Exercise saved.');
    }

    public function moveItem(Request $request, PlanDayExercise $item): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        DB::transaction(function () use ($item, $direction) {
            $sibling = PlanDayExercise::where('plan_day_id', $item->plan_day_id)
                ->where('position', $direction === 'up' ? '<' : '>', $item->position)
                ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')->first();
            if (! $sibling) {
                return;
            }
            // (plan_day_id, position) is unique, so park one row on 0 while the other moves.
            [$a, $b] = [$item->position, $sibling->position];
            $item->update(['position' => 0]);
            $sibling->update(['position' => $a]);
            $item->update(['position' => $b]);
        });
        $this->touchPlan($item->day);

        return back();
    }

    public function destroyItem(PlanDayExercise $item): RedirectResponse
    {
        $day = $item->day;
        DB::transaction(function () use ($item, $day) {
            $item->delete();
            // Close the gap so positions stay 1..n.
            $day->items()->get()->each(fn (PlanDayExercise $row, int $i) => $row->update(['position' => 100 + $i]));
            $day->items()->get()->each(fn (PlanDayExercise $row, int $i) => $row->update(['position' => $i + 1]));
        });
        $this->touchPlan($day);
        AdminAudit::record('plan.item.remove', ['plan_day_id' => $day->id, 'exercise_id' => $item->exercise_id]);

        return back()->with('status', 'Exercise removed from the day.');
    }

    private function touchPlan(PlanDay $day): void
    {
        Plan::whereKey($day->plan_id)->increment('version');
    }
}
