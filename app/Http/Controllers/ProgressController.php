<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeasurementRequest;
use App\Http\Requests\StorePhotoRequest;
use App\Models\Exercise;
use App\Models\Measurement;
use App\Models\ProgressPhoto;
use App\Services\BadgeService;
use App\Services\ProgressPhotoService;
use App\Services\ProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProgressController extends Controller
{
    public function __construct(
        private readonly ProgressService $progress,
        private readonly ProgressPhotoService $photos,
        private readonly BadgeService $badges,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load('profile');
        $summary = $this->progress->summary($user);
        $records = $this->progress->records($user);

        return view('progress.index', [
            'user' => $user,
            'summary' => $summary,
            'recent' => $this->progress->measurements($user, 400)->reverse()->take(8)->values(),
            'records' => $records,
            'photos' => ProgressPhoto::where('user_id', $user->id)->orderByDesc('taken_on')->orderByDesc('id')->limit(60)->get(),
            'target' => $user->profile?->target_weight_kg,
            'today' => $user->localToday(),
            'config' => [
                'weight' => $summary['weight_points'],
                'target' => $user->profile?->target_weight_kg,
                'exercises' => $records->map(fn ($r) => ['slug' => $r['exercise']->slug, 'name' => $r['exercise']->name])->all(),
                'strengthUrl' => url('/api/progress/exercises'),
            ],
        ]);
    }

    public function storeMeasurement(StoreMeasurementRequest $request): RedirectResponse
    {
        $user = $request->user()->load('profile');
        $data = $request->measurementData();
        $on = $request->input('measured_on');

        $existing = Measurement::where('user_id', $user->id)->whereDate('measured_on', $on)->first();
        $row = $existing ?? new Measurement(['user_id' => $user->id, 'measured_on' => $on]);
        // Fields left blank keep what was already stored for that day.
        $row->fill(array_filter($data, fn ($v) => $v !== null));
        $row->save();

        // The newest weight is the user's current weight: the diet page then offers to recalculate.
        $latest = Measurement::where('user_id', $user->id)->whereNotNull('weight_kg')->orderByDesc('measured_on')->first();
        if ($latest && $user->profile && abs($user->profile->weight_kg - $latest->weight_kg) > 0.04) {
            $user->profile->update(['weight_kg' => $latest->weight_kg]);
        }

        $awarded = $this->badges->evaluate($user->refresh(), ['body']);

        return redirect()->route('progress.index')->with('status', 'Measurements saved.'.($awarded ? ' Badge earned: '.collect($awarded)->pluck('name')->implode(', ').'.' : ''));
    }

    public function destroyMeasurement(Request $request, Measurement $measurement): RedirectResponse
    {
        abort_unless($measurement->user_id === $request->user()->id, 403);
        $measurement->delete();

        return redirect()->route('progress.index')->with('status', 'Measurement deleted.');
    }

    public function storePhoto(StorePhotoRequest $request): RedirectResponse
    {
        $user = $request->user();
        $this->photos->store($user, $request->file('photo'), $request->input('pose'), CarbonImmutable::parse($request->input('taken_on')), $request->input('notes'));
        $awarded = $this->badges->evaluate($user->refresh(), ['body']);

        return redirect()->route('progress.index')->with('status', 'Photo saved. Only you can see it.'.($awarded ? ' Badge earned: '.collect($awarded)->pluck('name')->implode(', ').'.' : ''));
    }

    /** Streams the (re-encoded) photo to its owner only. Never public, never cached by shared proxies. */
    public function showPhoto(ProgressPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $photo);
        abort_unless(Storage::disk(ProgressPhotoService::DISK)->exists($photo->path), 404);

        return Storage::disk(ProgressPhotoService::DISK)->response($photo->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyPhoto(ProgressPhoto $photo): RedirectResponse
    {
        Gate::authorize('delete', $photo);
        $this->photos->delete($photo);

        return redirect()->route('progress.index')->with('status', 'Photo deleted.');
    }

    /** Strength curve for one exercise (estimated 1RM and heaviest set per workout). */
    public function strength(Request $request, Exercise $exercise): JsonResponse
    {
        return response()->json(['exercise' => ['id' => $exercise->id, 'name' => $exercise->name], 'series' => $this->progress->strengthSeries($request->user(), $exercise)]);
    }
}
