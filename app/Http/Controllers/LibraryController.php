<?php

namespace App\Http\Controllers;

use App\Enums\Difficulty;
use App\Enums\ExerciseKind;
use App\Enums\Mechanic;
use App\Enums\MuscleGroup;
use App\Models\Exercise;
use App\Models\PlanDayExercise;
use App\Services\SwapService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    public function index(Request $request, SwapService $swaps): View
    {
        $user = $request->user()->load('profile');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'muscle' => ['nullable', 'in:'.implode(',', MuscleGroup::values())],
            'equipment' => ['nullable', 'in:'.implode(',', array_keys(config('ironpulse.equipment')))],
            'difficulty' => ['nullable', 'in:'.implode(',', Difficulty::values())],
            'kind' => ['nullable', 'in:'.implode(',', ExerciseKind::values())],
            'mechanic' => ['nullable', 'in:'.implode(',', Mechanic::values())],
            'usable' => ['nullable', 'boolean'],
        ]);

        $query = Exercise::query()->active();

        if ($q = $filters['q'] ?? null) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like));
        }
        foreach (['muscle' => 'muscle_group', 'difficulty' => 'difficulty', 'kind' => 'kind', 'mechanic' => 'mechanic'] as $param => $column) {
            if (! empty($filters[$param])) {
                $query->where($column, $filters[$param]);
            }
        }
        if ($tag = $filters['equipment'] ?? null) {
            // Mobility and bodyweight moves are tagged "bodyweight"; exact tag match is intended.
            $query->whereJsonContains('equipment', $tag);
        }

        $usable = ! empty($filters['usable']);
        if ($usable) {
            $owned = $swaps->ownedEquipment($user);
            $ids = (clone $query)->get()->filter(fn (Exercise $e) => $swaps->usableWith($e, $owned))->pluck('id');
            $query->whereIn('id', $ids);
        }

        $exercises = $query
            ->orderByRaw("CASE kind WHEN 'plan' THEN 0 WHEN 'alternative' THEN 1 WHEN 'helper' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('library.index', [
            'exercises' => $exercises,
            'filters' => $filters + ['usable' => $usable],
            'muscles' => MuscleGroup::cases(),
            'equipment' => config('ironpulse.equipment'),
            'difficulties' => Difficulty::cases(),
            'kinds' => ExerciseKind::cases(),
            'mechanics' => Mechanic::cases(),
            'regions' => config('ironpulse.regions'),
        ]);
    }

    public function show(Request $request, Exercise $exercise, SwapService $swaps): View
    {
        abort_unless($exercise->is_active, 404);

        $user = $request->user()->load('profile');

        $usedIn = PlanDayExercise::query()
            ->where('exercise_id', $exercise->id)
            ->with('day')
            ->get()
            ->sortBy(fn ($row) => $row->day->day_of_week);

        $related = Exercise::query()->active()
            ->where('muscle_group', $exercise->muscle_group)
            ->where('id', '!=', $exercise->id)
            ->whereIn('kind', ['plan', 'alternative'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Exercise $e) => $swaps->usableWith($e, $swaps->ownedEquipment($user)))
            ->take(6);

        $exercise->load('postureRules');

        return view('library.show', [
            'exercise' => $exercise,
            'usedIn' => $usedIn,
            'related' => $related,
            'regions' => config('ironpulse.regions'),
            'canDo' => $swaps->usableWith($exercise, $swaps->ownedEquipment($user)),
        ]);
    }
}
