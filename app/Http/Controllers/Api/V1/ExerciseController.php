<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Difficulty;
use App\Enums\ExerciseKind;
use App\Enums\Mechanic;
use App\Enums\MuscleGroup;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ExerciseResource;
use App\Models\Exercise;
use App\Services\SwapService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExerciseController extends Controller
{
    /** Search and filter the library (published exercises only), paginated. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'muscle' => ['nullable', 'in:'.implode(',', MuscleGroup::values())],
            'equipment' => ['nullable', 'in:'.implode(',', array_keys(config('ironpulse.equipment')))],
            'difficulty' => ['nullable', 'in:'.implode(',', Difficulty::values())],
            'kind' => ['nullable', 'in:'.implode(',', ExerciseKind::values())],
            'mechanic' => ['nullable', 'in:'.implode(',', Mechanic::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('ironpulse.mobile.page_size.max')],
        ]);

        $query = Exercise::query()->published()->with(['media', 'postureRules']);

        if ($q = $f['q'] ?? null) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like));
        }
        foreach (['muscle' => 'muscle_group', 'difficulty' => 'difficulty', 'kind' => 'kind', 'mechanic' => 'mechanic'] as $param => $column) {
            if (! empty($f[$param])) {
                $query->where($column, $f[$param]);
            }
        }
        if (! empty($f['equipment'])) {
            $query->whereJsonContains('equipment', $f['equipment']);
        }

        $page = $query
            ->orderByRaw("CASE kind WHEN 'plan' THEN 0 WHEN 'alternative' THEN 1 WHEN 'helper' THEN 2 ELSE 3 END")
            ->orderBy('name')->orderBy('id')
            ->paginate($f['per_page'] ?? config('ironpulse.mobile.page_size.default'), page: $f['page'] ?? 1);

        return ExerciseResource::collection($page);
    }

    public function show(Request $request, string $exercise): ExerciseResource
    {
        $found = Exercise::query()->published()->with(['media', 'postureRules'])->where('slug', $exercise)->first() ?? throw ApiException::notFound('Exercise not found.');

        // Premium exercises answer as a teaser with `locked: true` (see ExerciseResource); no extra detail leaks.
        return new ExerciseResource($found);
    }

    /** Same-muscle-group swaps the member can do with the equipment they have. */
    public function alternatives(Request $request, string $exercise, SwapService $swaps): AnonymousResourceCollection
    {
        $found = Exercise::query()->published()->where('slug', $exercise)->first() ?? throw ApiException::notFound('Exercise not found.');
        $user = $request->user()->load('profile');
        $owned = $swaps->ownedEquipment($user);

        $related = Exercise::query()->published()->with(['media', 'postureRules'])
            ->where('muscle_group', $found->muscle_group)->where('id', '!=', $found->id)
            ->whereIn('kind', ['plan', 'alternative'])->orderBy('name')->get()
            ->filter(fn (Exercise $e) => $swaps->usableWith($e, $owned))->take(8)->values();

        return ExerciseResource::collection($related);
    }
}
