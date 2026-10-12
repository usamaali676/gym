<?php

use App\Models\ContentChange;
use App\Models\Exercise;
use App\Models\ExerciseMedia;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    $this->dir = sys_get_temp_dir().'/demo-clips-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->exercise = Exercise::factory()->create(['slug' => 'goblet-squat', 'name' => 'Goblet squat']);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Write a tiny "rendered" folder: the import checks sizes and hashes, not that the bytes are a playable video. */
function renderedFolder(string $dir, string $slug, string $video = 'VIDEO-BYTES', string $poster = 'POSTER-BYTES', array $over = []): void
{
    File::put("$dir/$slug-angle.mp4", $video);
    File::put("$dir/$slug-angle.jpg", $poster);
    File::put("$dir/index.json", json_encode(['clips' => [array_merge([
        'slug' => $slug, 'view' => 'angle', 'video' => "$slug-angle.mp4", 'poster' => "$slug-angle.jpg",
        'width' => 640, 'height' => 640, 'duration_ms' => 3400,
        'bytes' => strlen($video), 'sha256' => hash('sha256', $video),
        'poster_bytes' => strlen($poster), 'poster_sha256' => hash('sha256', $poster),
    ], $over)], 'skipped' => []]));
}

it('lists only published exercises with their demo key and regions', function () {
    Exercise::factory()->draft()->create(['slug' => 'hidden-draft']);
    $this->exercise->update(['demo_key' => 'squat', 'target_regions' => ['primary' => ['quads'], 'secondary' => []]]);
    $out = 'storage/framework/testing/demo-manifest-'.uniqid().'.json';

    $this->artisan('exercises:demo-manifest', ['--out' => $out])->assertSuccessful();

    $list = collect(json_decode(File::get(base_path($out)), true));
    File::delete(base_path($out));
    expect($list->pluck('slug'))->toContain('goblet-squat')->not->toContain('hidden-draft');
    $row = $list->firstWhere('slug', 'goblet-squat');
    expect($row['demo_key'])->toBe('squat')->and($row['regions']['primary'])->toBe(['quads']);
});

it('registers a clip and its poster as published media the app can fetch', function () {
    renderedFolder($this->dir, 'goblet-squat');

    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertSuccessful();

    $video = ExerciseMedia::where('exercise_id', $this->exercise->id)->where('kind', 'demo_video')->sole();
    $poster = ExerciseMedia::where('exercise_id', $this->exercise->id)->where('kind', 'thumbnail')->sole();
    expect($video->view)->toBe('angle')->and($video->mime)->toBe('video/mp4')->and($video->status)->toBe('published')
        ->and($video->checksum)->toBe(hash('sha256', 'VIDEO-BYTES'))->and($video->duration_ms)->toBe(3400)
        ->and($video->width)->toBe(640)->and($poster->mime)->toBe('image/jpeg')->and($poster->duration_ms)->toBeNull();
    Storage::disk('media')->assertExists($video->path);
    expect(Storage::disk('media')->get($video->path))->toBe('VIDEO-BYTES');

    $user = User::factory()->onboarded()->create();
    $media = $this->getJson('/api/v1/exercises/goblet-squat', apiAs($user))->assertOk()->json('data.media');
    expect(collect($media)->pluck('kind')->all())->toContain('demo_video', 'thumbnail')
        ->and(collect($media)->firstWhere('kind', 'demo_video'))->toMatchArray(['view' => 'angle', 'mime' => 'video/mp4', 'duration_ms' => 3400]);
});

it('does nothing the second time when the files have not changed', function () {
    renderedFolder($this->dir, 'goblet-squat');
    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertSuccessful();
    $ids = ExerciseMedia::pluck('id')->sort()->values()->all();
    $changes = ContentChange::count();

    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertSuccessful();

    expect(ExerciseMedia::pluck('id')->sort()->values()->all())->toBe($ids)->and(ContentChange::count())->toBe($changes);
});

it('replaces a changed clip and removes the old file', function () {
    renderedFolder($this->dir, 'goblet-squat');
    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertSuccessful();
    $old = ExerciseMedia::where('kind', 'demo_video')->sole();

    renderedFolder($this->dir, 'goblet-squat', video: 'NEW-VIDEO');
    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertSuccessful();

    $new = ExerciseMedia::where('kind', 'demo_video')->sole();
    expect($new->id)->not->toBe($old->id)->and($new->checksum)->toBe(hash('sha256', 'NEW-VIDEO'));
    Storage::disk('media')->assertMissing($old->path);
    Storage::disk('media')->assertExists($new->path);
    expect(ExerciseMedia::where('kind', 'thumbnail')->count())->toBe(1);
});

it('refuses a file that does not match its index entry', function () {
    renderedFolder($this->dir, 'goblet-squat');
    File::put($this->dir.'/goblet-squat-angle.mp4', 'TAMPERED');

    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertFailed();

    expect(ExerciseMedia::where('kind', 'demo_video')->count())->toBe(0);
});

it('skips an exercise that does not exist and reports it', function () {
    renderedFolder($this->dir, 'no-such-exercise');

    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertFailed();

    expect(ExerciseMedia::count())->toBe(0);
});

it('changes nothing in a dry run', function () {
    renderedFolder($this->dir, 'goblet-squat');

    $this->artisan('exercises:import-demos', ['dir' => $this->dir, '--dry-run' => true])->assertSuccessful();

    expect(ExerciseMedia::count())->toBe(0);
    expect(Storage::disk('media')->allFiles())->toBe([]);
});

it('stops when there is no index', function () {
    $this->artisan('exercises:import-demos', ['dir' => $this->dir])->assertFailed();
});
