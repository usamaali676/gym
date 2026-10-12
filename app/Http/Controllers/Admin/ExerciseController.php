<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Difficulty;
use App\Enums\ExerciseKind;
use App\Enums\Mechanic;
use App\Enums\MuscleGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExerciseRequest;
use App\Models\Exercise;
use App\Models\PlanDayExercise;
use App\Models\PostureSession;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExerciseController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $kind = $request->query('kind');

        $exercises = Exercise::query()
            ->withCount('planItems')
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->when(in_array($kind, ExerciseKind::values(), true), fn ($query) => $query->where('kind', $kind))
            ->orderBy('kind')->orderBy('name')
            ->paginate(40)->withQueryString();

        return view('admin.exercises.index', ['exercises' => $exercises, 'q' => $q, 'kind' => $kind, 'kinds' => ExerciseKind::cases()]);
    }

    public function create(): View
    {
        return view('admin.exercises.form', $this->formData(new Exercise([
            'kind' => 'alternative', 'mechanic' => 'isolation', 'difficulty' => 'beginner', 'muscle_group' => 'chest',
            'rest_min_seconds' => 60, 'rest_max_seconds' => 90, 'default_rest_seconds' => 75, 'is_active' => true,
        ])));
    }

    public function store(ExerciseRequest $request): RedirectResponse
    {
        $exercise = Exercise::create($request->exerciseData());
        AdminAudit::record('exercise.create', ['exercise_id' => $exercise->id, 'slug' => $exercise->slug]);

        return redirect()->route('admin.exercises.edit', $exercise)->with('status', 'Exercise created.');
    }

    public function edit(Exercise $exercise): View
    {
        $exercise->loadCount('planItems');

        return view('admin.exercises.form', $this->formData($exercise));
    }

    public function update(ExerciseRequest $request, Exercise $exercise): RedirectResponse
    {
        $exercise->update($request->exerciseData());
        AdminAudit::record('exercise.update', ['exercise_id' => $exercise->id]);

        return redirect()->route('admin.exercises.edit', $exercise)->with('status', 'Exercise saved.');
    }

    /** Exercises that are in the plan or in anyone's history are retired, not deleted, so history stays readable. */
    public function destroy(Exercise $exercise): RedirectResponse
    {
        $used = PlanDayExercise::where('exercise_id', $exercise->id)->exists()
            || SessionExercise::where('exercise_id', $exercise->id)->orWhere('original_exercise_id', $exercise->id)->exists()
            || SessionSet::where('exercise_id', $exercise->id)->exists()
            || PostureSession::where('exercise_id', $exercise->id)->exists();

        if ($used) {
            $exercise->update(['is_active' => false]);
            AdminAudit::record('exercise.deactivate', ['exercise_id' => $exercise->id]);

            return redirect()->route('admin.exercises.index')->with('status', 'This exercise is in the plan or in workout history, so it was hidden instead of deleted.');
        }

        AdminAudit::record('exercise.delete', ['exercise_id' => $exercise->id, 'slug' => $exercise->slug]);
        $exercise->delete();

        return redirect()->route('admin.exercises.index')->with('status', 'Exercise deleted.');
    }

    /** @return array<string, mixed> */
    private function formData(Exercise $exercise): array
    {
        return [
            'exercise' => $exercise,
            'kinds' => ExerciseKind::cases(),
            'groups' => MuscleGroup::cases(),
            'mechanics' => Mechanic::cases(),
            'difficulties' => Difficulty::cases(),
            'equipment' => array_values(array_unique([...array_keys(config('ironpulse.equipment')), ...array_keys(config('ironpulse.equipment_map')), ...Exercise::pluck('equipment')->flatten()->filter()->all()])),
        ];
    }
}
