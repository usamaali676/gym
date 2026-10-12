<?php

use App\Models\Exercise;
use App\Models\Measurement;
use App\Models\PersonalRecord;
use App\Models\ProgressPhoto;
use App\Models\SessionExercise;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\ProgressPhotoService;
use App\Services\ProgressService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    seedLibrary();
    seedBadges();
    Carbon::setTestNow('2026-10-14 12:00:00');
    Storage::fake(ProgressPhotoService::DISK);
});
afterEach(fn () => Carbon::setTestNow());

function pUser(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create()->load('profile');
}

function measurementPayload(array $over = []): array
{
    return array_merge(['measured_on' => '2026-10-14', 'weight_kg' => 77.5], $over);
}

// ---------------------------------------------------------------------------------------------
// Measurements
// ---------------------------------------------------------------------------------------------

test('the progress page renders for an onboarded user with no data', function () {
    $user = pUser();

    $this->actingAs($user)->get('/progress')->assertOk()->assertSee('Progress')->assertDontSee('weight-chart', false);
});

test('the weight chart appears once there are two weigh-ins', function () {
    $user = pUser();
    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['measured_on' => '2026-10-07', 'weight_kg' => 79]));
    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['measured_on' => '2026-10-14', 'weight_kg' => 78]));

    $this->actingAs($user)->get('/progress')->assertOk()->assertSee('weight-chart', false);
});

test('progress requires login', function () {
    $this->get('/progress')->assertRedirect('/login');
});

test('logging a measurement stores it and moves the profile weight to the newest value', function () {
    $user = pUser(['weight_kg' => 80]);

    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['weight_kg' => 78.4, 'waist_cm' => 84]))->assertRedirect('/progress');

    $row = Measurement::where('user_id', $user->id)->sole();
    expect($row->weight_kg)->toBe(78.4)->and((float) $row->waist_cm)->toBe(84.0)
        ->and($user->profile->fresh()->weight_kg)->toBe(78.4);
});

test('logging an older entry does not overwrite the profile weight', function () {
    $user = pUser(['weight_kg' => 80]);
    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['measured_on' => '2026-10-14', 'weight_kg' => 79]));
    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['measured_on' => '2026-10-01', 'weight_kg' => 82]));

    expect($user->profile->fresh()->weight_kg)->toBe(79.0);
});

test('a second entry for the same day merges and blank fields keep earlier values', function () {
    $user = pUser();
    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['weight_kg' => 78, 'waist_cm' => 84]));
    $this->actingAs($user)->post('/progress/measurements', measurementPayload(['weight_kg' => '', 'chest_cm' => 101]));

    $row = Measurement::where('user_id', $user->id)->sole();
    expect($row->weight_kg)->toBe(78.0)->and((float) $row->waist_cm)->toBe(84.0)->and((float) $row->chest_cm)->toBe(101.0);
});

test('measurement input is validated', function (array $bad, string $field) {
    $user = pUser();

    $this->actingAs($user)->post('/progress/measurements', measurementPayload($bad))->assertSessionHasErrors($field);
    expect(Measurement::count())->toBe(0);
})->with([
    'weight too low' => [['weight_kg' => 10], 'weight_kg'],
    'weight too high' => [['weight_kg' => 900], 'weight_kg'],
    'future date' => [['measured_on' => '2026-10-20'], 'measured_on'],
    'ancient date' => [['measured_on' => '2020-01-01'], 'measured_on'],
    'bad date format' => [['measured_on' => '14/10/2026'], 'measured_on'],
    'body fat out of range' => [['body_fat_pct' => 90], 'body_fat_pct'],
    'tape out of range' => [['waist_cm' => 3], 'waist_cm'],
    'nothing entered' => [['weight_kg' => ''], 'weight_kg'],
]);

test('a user can only delete their own measurements', function () {
    $owner = pUser();
    $other = pUser();
    $m = Measurement::create(['user_id' => $owner->id, 'measured_on' => '2026-10-10', 'weight_kg' => 80]);

    $this->actingAs($other)->delete("/progress/measurements/{$m->id}")->assertForbidden();
    expect(Measurement::count())->toBe(1);

    $this->actingAs($owner)->delete("/progress/measurements/{$m->id}")->assertRedirect('/progress');
    expect(Measurement::count())->toBe(0);
});

test('the page shows bmi, the 30 day change and an estimated body fat from tape measurements', function () {
    $user = pUser(['weight_kg' => 80, 'height_cm' => 180, 'sex' => 'male']);
    Measurement::create(['user_id' => $user->id, 'measured_on' => '2026-09-10', 'weight_kg' => 82]);
    Measurement::create(['user_id' => $user->id, 'measured_on' => '2026-10-13', 'weight_kg' => 80, 'neck_cm' => 38, 'waist_cm' => 85]);

    $page = $this->actingAs($user)->get('/progress')->assertOk();

    $page->assertSee('24.7')           // BMI 80 / 1.8^2
        ->assertSee('-2');             // change over the period
    $summary = app(ProgressService::class)->summary($user->fresh('profile'));
    expect($summary['body_fat_source'])->toBe('estimated')->and($summary['body_fat'])->toBeBetween(15.5, 16.5);
});

test('a logged body fat beats the tape estimate', function () {
    $user = pUser();
    Measurement::create(['user_id' => $user->id, 'measured_on' => '2026-10-13', 'weight_kg' => 80, 'neck_cm' => 38, 'waist_cm' => 85, 'body_fat_pct' => 18.5]);

    $summary = app(ProgressService::class)->summary($user->fresh('profile'));

    expect($summary['body_fat'])->toBe(18.5)->and($summary['body_fat_source'])->toBe('logged');
});

test('measurements fetched with the date column cast are found by whereDate even with a time part', function () {
    $user = pUser();
    $this->actingAs($user)->post('/progress/measurements', measurementPayload());

    $found = app(ProgressService::class)->measurements($user, 30);

    expect($found)->toHaveCount(1)->and($found->first()->measured_on->toDateString())->toBe('2026-10-14');
});

test('the first body logs award the measurement badge once', function () {
    $user = pUser();
    foreach (range(0, 2) as $i) {
        $this->actingAs($user)->post('/progress/measurements', measurementPayload(['measured_on' => Carbon::parse('2026-10-14')->subDays($i)->toDateString(), 'weight_kg' => 78 - $i]));
    }

    $earned = $user->userBadges()->with('badge')->get()->pluck('badge.slug');
    expect($earned->count())->toBe($earned->unique()->count());
});

// ---------------------------------------------------------------------------------------------
// Photos
// ---------------------------------------------------------------------------------------------

function fakePhoto(int $w = 600, int $h = 800, string $name = 'me.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

test('an uploaded photo is re-encoded as a jpeg on the private disk and is not publicly reachable', function () {
    $user = pUser();

    $this->actingAs($user)->post('/progress/photos', ['photo' => fakePhoto(), 'pose' => 'front', 'taken_on' => '2026-10-14'])->assertRedirect('/progress');

    $photo = ProgressPhoto::where('user_id', $user->id)->sole();
    Storage::disk(ProgressPhotoService::DISK)->assertExists($photo->path);
    expect($photo->path)->toStartWith("progress/{$user->id}/")->and($photo->mime)->toBe('image/jpeg')->and($photo->path)->toEndWith('.jpg');
    expect(Storage::disk('public')->exists($photo->path))->toBeFalse();
});

test('exif metadata including gps is stripped from stored photos', function () {
    $user = pUser();
    $src = tempnam(sys_get_temp_dir(), 'ip').'.jpg';
    $img = imagecreatetruecolor(400, 400);
    imagefilledrectangle($img, 0, 0, 400, 400, imagecolorallocate($img, 200, 30, 30));
    imagejpeg($img, $src);
    // Splice a fake EXIF APP1 segment (with a recognisable marker) in after the SOI marker.
    $jpeg = file_get_contents($src);
    $exif = "Exif\0\0".'II*'."\0\x08\0\0\0\0\0\0\0".'SECRET-GPS-MARKER';
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
    file_put_contents($src, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    expect(file_get_contents($src))->toContain('SECRET-GPS-MARKER');

    $this->actingAs($user)->post('/progress/photos', [
        'photo' => new UploadedFile($src, 'x.jpg', 'image/jpeg', null, true), 'pose' => 'side', 'taken_on' => '2026-10-14',
    ])->assertRedirect('/progress');

    $stored = Storage::disk(ProgressPhotoService::DISK)->get(ProgressPhoto::sole()->path);
    expect($stored)->not->toContain('SECRET-GPS-MARKER')->and(substr($stored, 0, 3))->toBe("\xFF\xD8\xFF");
    @unlink($src);
});

test('large photos are scaled down', function () {
    $user = pUser();
    $this->actingAs($user)->post('/progress/photos', ['photo' => fakePhoto(3200, 2400), 'pose' => 'back', 'taken_on' => '2026-10-14'])->assertRedirect('/progress');

    $stored = Storage::disk(ProgressPhotoService::DISK)->get(ProgressPhoto::sole()->path);
    [$w, $h] = getimagesizefromstring($stored);
    expect(max($w, $h))->toBeLessThanOrEqual(1600);
});

test('only the owner can view or delete a photo, and it is streamed with private cache headers', function () {
    $owner = pUser();
    $other = pUser();
    $this->actingAs($owner)->post('/progress/photos', ['photo' => fakePhoto(), 'pose' => 'front', 'taken_on' => '2026-10-14']);
    $photo = ProgressPhoto::sole();

    $this->actingAs($other)->get("/progress/photos/{$photo->id}")->assertForbidden();
    $this->actingAs($other)->delete("/progress/photos/{$photo->id}")->assertForbidden();

    $res = $this->actingAs($owner)->get("/progress/photos/{$photo->id}")->assertOk();
    auth()->logout();
    $this->get("/progress/photos/{$photo->id}")->assertRedirect('/login');
    expect($res->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($res->headers->get('Cache-Control'))->toContain('private')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('deleting a photo removes the file as well as the row', function () {
    $user = pUser();
    $this->actingAs($user)->post('/progress/photos', ['photo' => fakePhoto(), 'pose' => 'front', 'taken_on' => '2026-10-14']);
    $photo = ProgressPhoto::sole();

    $this->actingAs($user)->delete("/progress/photos/{$photo->id}")->assertRedirect('/progress');

    expect(ProgressPhoto::count())->toBe(0);
    Storage::disk(ProgressPhotoService::DISK)->assertMissing($photo->path);
});

test('photo uploads reject non images, tiny images and bad input', function () {
    $user = pUser();
    $base = ['pose' => 'front', 'taken_on' => '2026-10-14'];

    $this->actingAs($user)->post('/progress/photos', $base + ['photo' => UploadedFile::fake()->create('x.pdf', 20, 'application/pdf')])->assertSessionHasErrors('photo');
    $this->actingAs($user)->post('/progress/photos', $base + ['photo' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')])->assertSessionHasErrors('photo');
    $this->actingAs($user)->post('/progress/photos', $base + ['photo' => fakePhoto(50, 50)])->assertSessionHasErrors('photo');
    $this->actingAs($user)->post('/progress/photos', ['photo' => fakePhoto(), 'pose' => 'upside-down', 'taken_on' => '2026-10-14'])->assertSessionHasErrors('pose');
    $this->actingAs($user)->post('/progress/photos', ['photo' => fakePhoto(), 'pose' => 'front', 'taken_on' => '2027-01-01'])->assertSessionHasErrors('taken_on');

    expect(ProgressPhoto::count())->toBe(0);
});

test('users are capped at a maximum number of photos', function () {
    $user = pUser();
    for ($i = 0; $i < ProgressPhotoService::MAX_PER_USER; $i++) {
        ProgressPhoto::create(['user_id' => $user->id, 'taken_on' => '2026-10-01', 'pose' => 'front', 'path' => "progress/{$user->id}/{$i}.jpg", 'mime' => 'image/jpeg', 'size_bytes' => 1]);
    }

    $this->actingAs($user)->post('/progress/photos', ['photo' => fakePhoto(), 'pose' => 'front', 'taken_on' => '2026-10-14'])->assertSessionHasErrors('photo');
});

// ---------------------------------------------------------------------------------------------
// Strength
// ---------------------------------------------------------------------------------------------

function completedSet(User $user, Exercise $exercise, string $day, float $kg, int $reps): void
{
    $session = WorkoutSession::create([
        'user_id' => $user->id, 'scheduled_for' => $day, 'source' => 'game', 'status' => 'completed',
        'client_uuid' => (string) Str::uuid(), 'started_at' => $day.' 10:00:00', 'ended_at' => $day.' 11:00:00',
    ]);
    $se = SessionExercise::create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => 1, 'target_sets' => 3, 'target_reps_min' => 6, 'target_reps_max' => 10]);
    SessionSet::create([
        'session_exercise_id' => $se->id, 'workout_session_id' => $session->id, 'exercise_id' => $exercise->id,
        'set_number' => 1, 'weight_kg' => $kg, 'reps' => $reps, 'completed_at' => $day.' 10:30:00',
    ]);
}

test('the strength api returns one point per workout with an estimated one rep max', function () {
    $user = pUser();
    $bench = Exercise::where('slug', 'like', '%bench%')->firstOrFail();
    completedSet($user, $bench, '2026-10-05', 60, 10);
    completedSet($user, $bench, '2026-10-12', 65, 8);

    $res = $this->actingAs($user)->getJson("/api/progress/exercises/{$bench->slug}")->assertOk();

    expect($res->json('series'))->toHaveCount(2)
        ->and($res->json('series.0.date'))->toBe('2026-10-05')
        ->and($res->json('series.0.top_weight'))->toBe(60)
        ->and($res->json('series.1.e1rm'))->toBeGreaterThan(65);
});

test('the strength api only ever returns the callers own sets', function () {
    $mine = pUser();
    $theirs = pUser();
    $bench = Exercise::where('slug', 'like', '%bench%')->firstOrFail();
    completedSet($theirs, $bench, '2026-10-05', 100, 5);

    $this->actingAs($mine)->getJson("/api/progress/exercises/{$bench->slug}")->assertOk()->assertJsonCount(0, 'series');
    auth()->logout();
    $this->getJson("/api/progress/exercises/{$bench->slug}")->assertUnauthorized();
});

test('warm up and skipped sessions are left out of the strength curve', function () {
    $user = pUser();
    $bench = Exercise::where('slug', 'like', '%bench%')->firstOrFail();
    completedSet($user, $bench, '2026-10-05', 60, 10);
    SessionSet::query()->update(['is_warmup' => true]);
    completedSet($user, $bench, '2026-10-06', 70, 5);
    WorkoutSession::whereDate('scheduled_for', '2026-10-06')->update(['status' => 'abandoned']);

    $this->actingAs($user)->getJson("/api/progress/exercises/{$bench->slug}")->assertOk()->assertJsonCount(0, 'series');
});

test('personal records show up grouped by exercise on the page', function () {
    $user = pUser();
    $bench = Exercise::where('slug', 'like', '%bench%')->firstOrFail();
    PersonalRecord::create(['user_id' => $user->id, 'exercise_id' => $bench->id, 'type' => 'max_weight', 'value' => 80, 'weight_kg' => 80, 'reps' => 5, 'achieved_at' => '2026-10-10 10:00:00']);

    $this->actingAs($user)->get('/progress')->assertOk()->assertSee($bench->name);
});
