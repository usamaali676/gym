<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeasurementRequest;
use App\Http\Requests\StorePhotoRequest;
use App\Models\Exercise;
use App\Models\Measurement;
use App\Models\ProgressPhoto;
use App\Services\BadgeService;
use App\Services\MeasurementService;
use App\Services\Membership\EntitlementService;
use App\Services\ProgressPhotoService;
use App\Services\ProgressService;
use App\Support\BadgePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Body measurements, strength history, personal records and private progress photos. */
class ProgressController extends Controller
{
    public function __construct(
        private readonly ProgressService $progress,
        private readonly ProgressPhotoService $photos,
        private readonly MeasurementService $measurements,
        private readonly BadgeService $badges,
    ) {}

    /** Weight trend, BMI / body-fat and the most recent measurements in one call. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user()->load('profile');
        $s = $this->progress->summary($user);

        return response()->json(['data' => [
            'latest' => $s['latest'] ? $this->measurement($s['latest']) : null,
            'weight_points' => $s['weight_points'],
            'target_weight_kg' => $user->profile?->target_weight_kg,
            'change_30d_kg' => $s['change_30d'],
            'trend_per_week_kg' => $s['trend_per_week'],
            'bmi' => $s['bmi'],
            'bmi_category' => $s['bmi_category'],
            'body_fat_pct' => $s['body_fat'],
            'body_fat_source' => $s['body_fat_source'],
            'lean_kg' => $s['lean_kg'],
        ]]);
    }

    public function measurements(Request $request): JsonResponse
    {
        $params = $request->validate(['days' => ['nullable', 'integer', 'between:1,1100']]);

        return response()->json(['data' => $this->progress->measurements($request->user()->load('profile'), $params['days'] ?? 180)->reverse()->values()->map(fn (Measurement $m) => $this->measurement($m))->all()]);
    }

    /** One row per day: fields left blank keep what was stored for that day. */
    public function storeMeasurement(StoreMeasurementRequest $request): JsonResponse
    {
        $result = $this->measurements->save($request->user()->load('profile'), $request->input('measured_on'), $request->measurementData());

        return response()->json(['data' => ['measurement' => $this->measurement($result['measurement']), 'badges' => BadgePresenter::many($result['badges'])]], 201);
    }

    public function destroyMeasurement(Request $request, int $measurement): JsonResponse
    {
        (Measurement::where('user_id', $request->user()->id)->find($measurement) ?? throw ApiException::notFound('Measurement not found.'))->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Heaviest set and estimated 1RM per workout for one exercise. */
    public function strength(Request $request, string $exercise): JsonResponse
    {
        $found = Exercise::query()->published()->where('slug', $exercise)->first() ?? throw ApiException::notFound('Exercise not found.');

        return response()->json(['data' => ['exercise' => ['id' => $found->id, 'slug' => $found->slug, 'name' => $found->name], 'series' => $this->progress->strengthSeries($request->user(), $found)]]);
    }

    public function records(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->progress->records($request->user())->map(fn (array $r) => [
            'exercise' => ['id' => $r['exercise']->id, 'slug' => $r['exercise']->slug, 'name' => $r['exercise']->name],
            'records' => collect($r['records'])->map(fn ($rec) => ['value' => (float) $rec->value, 'weight_kg' => $rec->weight_kg !== null ? (float) $rec->weight_kg : null, 'previous' => $rec->previous_value !== null ? (float) $rec->previous_value : null, 'achieved_at' => $rec->achieved_at?->toIso8601String()])->all(),
            'latest' => $r['latest']?->toIso8601String(),
        ])->values()->all()]);
    }

    // ------------------------------------------------------------------ photos (owner only)

    public function photos(Request $request): JsonResponse
    {
        return response()->json(['data' => ProgressPhoto::where('user_id', $request->user()->id)->orderByDesc('taken_on')->orderByDesc('id')->limit(120)->get()->map(fn (ProgressPhoto $p) => $this->photo($p))->all()]);
    }

    public function storePhoto(StorePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        app(EntitlementService::class)->assertWithinLimit($user, 'progress_photos', ProgressPhoto::where('user_id', $user->id)->count(), 'progress photos');

        $photo = $this->photos->store($user, $request->file('photo'), $request->input('pose'), CarbonImmutable::parse($request->input('taken_on')), $request->input('notes'));
        $awarded = $this->badges->evaluate($user->refresh(), ['body']);

        return response()->json(['data' => ['photo' => $this->photo($photo), 'badges' => BadgePresenter::many($awarded)]], 201);
    }

    /** The re-encoded JPEG, to its owner only (bearer token required; no public or signed link exists). */
    public function showPhoto(Request $request, int $photo): StreamedResponse
    {
        $row = ProgressPhoto::where('user_id', $request->user()->id)->find($photo) ?? throw ApiException::notFound('Photo not found.');
        abort_unless(Storage::disk(ProgressPhotoService::DISK)->exists($row->path), 404);

        return Storage::disk(ProgressPhotoService::DISK)->response($row->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyPhoto(Request $request, int $photo): JsonResponse
    {
        $this->photos->delete(ProgressPhoto::where('user_id', $request->user()->id)->find($photo) ?? throw ApiException::notFound('Photo not found.'));

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @return array<string, mixed> */
    private function measurement(Measurement $m): array
    {
        return ['id' => $m->id, 'measured_on' => $m->measured_on->toDateString(), 'weight_kg' => $m->weight_kg, 'body_fat_pct' => $m->body_fat_pct, 'notes' => $m->notes]
            + collect(StoreMeasurementRequest::SITES)->mapWithKeys(fn (string $s) => ["{$s}_cm" => $m->{"{$s}_cm"}])->all();
    }

    /** @return array<string, mixed> */
    private function photo(ProgressPhoto $p): array
    {
        return ['id' => $p->id, 'taken_on' => $p->taken_on->toDateString(), 'pose' => $p->pose, 'notes' => $p->notes, 'size_bytes' => (int) $p->size_bytes, 'url' => route('v1.progress.photos.show', $p->id, false)];
    }
}
