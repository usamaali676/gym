<?php

use App\Models\Exercise;
use App\Models\PostureRule;
use App\Models\PostureSession;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    seedPosture();
    Carbon::setTestNow('2026-10-12 09:00:00'); // Monday: push / chest
});
afterEach(fn () => Carbon::setTestNow());

/** Point the lab at a folder that does (or does not) contain the MediaPipe files. */
function fakeAssets(bool $installed): string
{
    $dir = sys_get_temp_dir().'/ip-mediapipe-'.uniqid();
    if ($installed) {
        File::ensureDirectoryExists("$dir/models");
        File::ensureDirectoryExists("$dir/wasm");
        file_put_contents("$dir/models/pose_landmarker_lite.task", 'x');
        file_put_contents("$dir/wasm/vision_wasm_internal.wasm", 'x');
    }
    config(['ironpulse.posture.assets_dir' => $dir]);

    return $dir;
}

test('the lab needs a login', function () {
    $this->get('/posture')->assertRedirect('/login');
    $this->get('/posture/barbell-squat')->assertRedirect('/login');
});

test('the lab index groups exercises by tier and puts todays lifts first', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/posture')
        ->assertOk()
        ->assertSee("Today's lifts", false)
        ->assertSeeInOrder(["Today's lifts", 'Flat Barbell Bench Press', 'Full-rule lifts'], escape: false)
        ->assertSee('Machines and cables')
        ->assertSee('Warm-up helpers')
        ->assertSee('Cable Overhead Tricep Extension')
        ->assertDontSee('not installed on this server');
});

test('the index warns when the model files are not installed', function () {
    fakeAssets(false);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/posture')->assertOk()->assertSee('The pose model files are not installed')->assertSee('npm run vendor:mediapipe');
});

test('the lab index shows recent sets and 30 day stats', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();
    $squat = Exercise::where('slug', 'barbell-squat')->firstOrFail();
    PostureSession::factory()->for($user)->for($squat)->create(['good_reps' => 7, 'total_reps' => 8, 'bad_reps' => 1, 'form_score_avg' => 88, 'started_at' => now()->subDay()]);

    $this->actingAs($user)->get('/posture')->assertOk()->assertSee('7 of 8 reps clean')->assertSee('88');
});

test('a lab page embeds the rule, the guidance and the self-hosted asset urls', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->get('/posture/barbell-squat')->assertOk()
        ->assertSee('Barbell Squat')
        ->assertSee('Film from the side')
        ->assertSee('Your camera stays private')
        ->assertSee('How accurate is it?')
        ->assertSee('The skeleton turns red when')
        ->assertSee('Torso too far forward');

    $html = $response->getContent();
    preg_match('#<script type="application/json" id="lab-config">(.*?)</script>#s', $html, $m);
    $lab = json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);

    expect($lab['exercise']['slug'])->toBe('barbell-squat')
        ->and($lab['rule']['tier'])->toBe('full')
        ->and($lab['rule']['config']['rep']['signal'])->toBe('knee')
        ->and($lab['assets']['ready'])->toBeTrue()
        ->and($lab['assets']['model'])->toEndWith('/vendor/mediapipe/models/pose_landmarker_lite.task')
        ->and($lab['assets']['wasm'])->toEndWith('/vendor/mediapipe/wasm')
        ->and($lab['urls']['save'])->toEndWith('/api/posture-sessions')
        ->and($lab['workoutSessionId'])->toBeNull();
    // Never a third-party host anywhere in the page.
    expect($html)->not->toContain('storage.googleapis.com')->not->toContain('cdn.jsdelivr')->not->toContain('unpkg.com');
});

test('machine lifts say plainly that accuracy is limited', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/posture/leg-press')->assertOk()->assertSee('Machines and cables hide parts of your body');
});

test('an exercise without a rule has no lab page', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/posture/goblet-squat')->assertNotFound();
    $this->actingAs($user)->get('/posture/does-not-exist')->assertNotFound();
});

test('a deactivated rule or exercise has no lab page', function () {
    $user = User::factory()->onboarded()->create();
    Exercise::where('slug', 'barbell-squat')->firstOrFail()->postureRules()->update(['is_active' => false]);
    Exercise::where('slug', 'plank')->update(['is_active' => false]);

    $this->actingAs($user)->get('/posture/barbell-squat')->assertNotFound();
    $this->actingAs($user)->get('/posture/plank')->assertNotFound();
});

test('a workout session passed in the url is linked only if it belongs to the user and is in progress', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/workout')->assertRedirect();
    $mine = WorkoutSession::where('user_id', $user->id)->firstOrFail();
    $this->actingAs($other)->get('/workout')->assertRedirect();
    $theirs = WorkoutSession::where('user_id', $other->id)->firstOrFail();

    $page = $this->actingAs($user)->get("/posture/flat-barbell-bench-press?session={$mine->id}")->assertOk()->assertSee('Back to workout');
    preg_match('#id="lab-config">(.*?)</script>#s', $page->getContent(), $m);
    expect(json_decode(html_entity_decode($m[1]), true)['workoutSessionId'])->toBe($mine->id);

    $this->actingAs($user)->get("/posture/flat-barbell-bench-press?session={$theirs->id}")->assertOk()->assertDontSee('Back to workout');
});

test('the lab page shows the users last sets for that exercise', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();
    $squat = Exercise::where('slug', 'barbell-squat')->firstOrFail();
    PostureSession::factory()->for($user)->for($squat)->create(['good_reps' => 4, 'total_reps' => 5, 'bad_reps' => 1, 'form_score_avg' => 91]);

    $this->actingAs($user)->get('/posture/barbell-squat')->assertOk()->assertSee('Your last sets')->assertSee('4/5 clean');
});

test('every seeded rule renders its lab page', function () {
    fakeAssets(true);
    $user = User::factory()->onboarded()->create();

    foreach (PostureRule::with('exercise')->get() as $rule) {
        $this->actingAs($user)->get("/posture/{$rule->exercise->slug}")->assertOk()->assertSee($rule->exercise->name);
    }
});

test('the security headers allow the camera and wasm but nothing third-party', function () {
    $user = User::factory()->onboarded()->create();
    $response = $this->actingAs($user)->get('/posture/barbell-squat');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=(self)');
    $csp = $response->headers->get('Content-Security-Policy');
    if ($csp) {
        expect($csp)->toContain("'wasm-unsafe-eval'")->toContain("connect-src 'self'")->not->toContain('https://');
    }
});
