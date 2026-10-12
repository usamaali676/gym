<?php

use App\Models\Exercise;
use App\Models\PostureSession;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\XpEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPosture();
    Carbon::setTestNow('2026-10-12 09:00:00');
});
afterEach(fn () => Carbon::setTestNow());

/** A valid payload for the squat. */
function postureSetPayload(string $slug = 'barbell-squat', array $overrides = []): array
{
    $exercise = Exercise::where('slug', $slug)->with('postureRules')->firstOrFail();
    $rule = $exercise->activePostureRule();

    return array_replace([
        'client_uuid' => (string) Str::uuid(),
        'exercise_id' => $exercise->id,
        'posture_rule_id' => $rule->id,
        'rule_version' => $rule->version,
        'camera_view' => $rule->camera_view->value,
        'started_at' => now()->subMinute()->toIso8601String(),
        'duration_seconds' => 42,
        'total_reps' => 6,
        'good_reps' => 5,
        'bad_reps' => 1,
        'hold_seconds' => null,
        'form_score_avg' => 88,
        'form_score_min' => 65,
        'error_counts' => ['forward_lean' => 1],
        'rep_log' => collect(range(1, 6))->map(fn ($n) => [
            'n' => $n, 'ms' => 2100, 'rom' => 1.0, 'score' => $n === 4 ? 65 : 94, 'good' => $n !== 4, 'partial' => false, 'errors' => $n === 4 ? ['forward_lean'] : [],
        ])->all(),
        'avg_fps' => 27.4,
        'model_variant' => 'lite',
    ], $overrides);
}

test('saving a set stores the summary and pays xp once', function () {
    $user = User::factory()->onboarded()->create();
    $payload = postureSetPayload();

    $res = $this->actingAs($user)->postJson('/api/posture-sessions', $payload)->assertCreated();

    $session = PostureSession::firstOrFail();
    expect($session->user_id)->toBe($user->id)
        ->and($session->total_reps)->toBe(6)->and($session->good_reps)->toBe(5)
        ->and($session->form_score_avg)->toBe(88)
        ->and($session->error_counts)->toBe(['forward_lean' => 1])
        ->and($session->rep_log)->toHaveCount(6)
        ->and($session->model_variant)->toBe('lite');

    // 20 for the set + 1 per clean rep (5)
    expect($res->json('xp'))->toBe(['session' => 20, 'reps' => 5, 'total' => 25])
        ->and($user->fresh()->xp_total)->toBe(25)
        ->and($res->json('level.xp_total'))->toBe(25);
});

test('retrying the same save is idempotent and pays nothing twice', function () {
    $user = User::factory()->onboarded()->create();
    $payload = postureSetPayload();

    $this->actingAs($user)->postJson('/api/posture-sessions', $payload)->assertCreated();
    $again = $this->actingAs($user)->postJson('/api/posture-sessions', $payload)->assertOk();

    expect(PostureSession::count())->toBe(1)
        ->and($again->json('created'))->toBeFalse()
        ->and($again->json('xp.total'))->toBe(0)
        ->and($user->fresh()->xp_total)->toBe(25)
        ->and(XpEvent::where('user_id', $user->id)->count())->toBe(2);
});

test("one person's client uuid cannot be replayed by another account", function () {
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();
    $payload = postureSetPayload();

    $this->actingAs($a)->postJson('/api/posture-sessions', $payload)->assertCreated();
    $this->actingAs($b)->postJson('/api/posture-sessions', $payload)->assertStatus(409);

    expect($b->fresh()->xp_total)->toBe(0)->and(PostureSession::count())->toBe(1);
});

test('xp from clean reps is capped per set and per day', function () {
    $user = User::factory()->onboarded()->create();

    // 40 clean reps in one set: only 30 pay out
    $big = postureSetPayload('barbell-squat', ['total_reps' => 40, 'good_reps' => 40, 'bad_reps' => 0, 'rep_log' => [], 'error_counts' => [], 'form_score_min' => 80]);
    $res = $this->actingAs($user)->postJson('/api/posture-sessions', $big)->assertCreated();
    expect($res->json('xp.reps'))->toBe(30);

    // two more big sets the same day: the daily cap of 100 leaves 70 for the second..fourth
    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['client_uuid' => (string) Str::uuid(), 'total_reps' => 40, 'good_reps' => 40, 'bad_reps' => 0, 'rep_log' => [], 'error_counts' => [], 'form_score_min' => 80]))->assertCreated();
    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['client_uuid' => (string) Str::uuid(), 'total_reps' => 40, 'good_reps' => 40, 'bad_reps' => 0, 'rep_log' => [], 'error_counts' => [], 'form_score_min' => 80]))->assertCreated();
    $fourth = $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['client_uuid' => (string) Str::uuid(), 'total_reps' => 40, 'good_reps' => 40, 'bad_reps' => 0, 'rep_log' => [], 'error_counts' => [], 'form_score_min' => 80]))->assertCreated();

    expect((int) XpEvent::where('user_id', $user->id)->where('type', 'posture_good_rep')->sum('amount'))->toBe(100)
        ->and($fourth->json('xp.reps'))->toBe(10);
});

test('tiny sets are saved but earn no xp', function () {
    $user = User::factory()->onboarded()->create();
    $payload = postureSetPayload('barbell-squat', ['total_reps' => 2, 'good_reps' => 2, 'bad_reps' => 0, 'rep_log' => [], 'error_counts' => [], 'form_score_min' => 90, 'form_score_avg' => 92]);

    $res = $this->actingAs($user)->postJson('/api/posture-sessions', $payload)->assertCreated();

    expect($res->json('xp.total'))->toBe(0)->and(PostureSession::count())->toBe(1)->and($user->fresh()->xp_total)->toBe(0);
});

test('a timed hold stores the hold seconds and earns the set xp', function () {
    $user = User::factory()->onboarded()->create();
    $payload = postureSetPayload('plank', ['total_reps' => 1, 'good_reps' => 1, 'bad_reps' => 0, 'hold_seconds' => 45, 'duration_seconds' => 60, 'rep_log' => [['n' => 1, 'ms' => 45000, 'score' => 92, 'good' => true, 'hold' => true, 'errors' => []]], 'error_counts' => [], 'form_score_min' => 92, 'form_score_avg' => 92]);

    $res = $this->actingAs($user)->postJson('/api/posture-sessions', $payload)->assertCreated();

    expect(PostureSession::firstOrFail()->hold_seconds)->toBe(45)->and($res->json('xp.session'))->toBe(20);
});

test('a set can be linked to the users own workout session only', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $this->actingAs($user)->get('/workout');
    $mine = WorkoutSession::where('user_id', $user->id)->firstOrFail();
    $this->actingAs($other)->get('/workout');
    $theirs = WorkoutSession::where('user_id', $other->id)->firstOrFail();

    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['workout_session_id' => $mine->id]))->assertCreated();
    expect(PostureSession::firstOrFail()->workout_session_id)->toBe($mine->id);

    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['workout_session_id' => $theirs->id]))
        ->assertStatus(422)->assertJsonValidationErrors('workout_session_id');
});

test('invalid summaries are rejected', function (array $override, string $field) {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', $override))
        ->assertStatus(422)->assertJsonValidationErrors($field);

    expect(PostureSession::count())->toBe(0);
})->with([
    'good plus bad must equal total' => [['good_reps' => 3, 'bad_reps' => 1], 'total_reps'],
    'more clean reps than reps' => [['total_reps' => 2, 'good_reps' => 5, 'bad_reps' => 0], 'good_reps'],
    'nothing was counted' => [['total_reps' => 0, 'good_reps' => 0, 'bad_reps' => 0, 'rep_log' => [], 'error_counts' => []], 'total_reps'],
    'score out of range' => [['form_score_avg' => 140], 'form_score_avg'],
    'lowest score above the average' => [['form_score_min' => 95, 'form_score_avg' => 90], 'form_score_min'],
    'absurd duration' => [['duration_seconds' => 99999], 'duration_seconds'],
    'impossibly many reps for the time (a modified app)' => [['duration_seconds' => 5, 'total_reps' => 40, 'good_reps' => 40, 'bad_reps' => 0, 'rep_log' => []], 'total_reps'],
    'started in the future' => [['started_at' => '2030-01-01T00:00:00Z'], 'started_at'],
    'unknown camera view' => [['camera_view' => 'overhead'], 'camera_view'],
    'unknown model' => [['model_variant' => 'gigantic'], 'model_variant'],
    'bad uuid' => [['client_uuid' => 'not-a-uuid'], 'client_uuid'],
    'unknown exercise' => [['exercise_id' => 999999, 'posture_rule_id' => null], 'exercise_id'],
    'error ids must be identifiers' => [['error_counts' => ['<script>' => 2]], 'error_counts'],
    'too many log entries' => [['rep_log' => array_fill(0, 201, ['n' => 1, 'ms' => 1000, 'score' => 90, 'good' => true, 'errors' => []])], 'rep_log'],
    'rep log error ids must be identifiers' => [['rep_log' => [['n' => 1, 'ms' => 1000, 'score' => 90, 'good' => true, 'errors' => ['DROP TABLE']]]], 'rep_log.0.errors.0'],
]);

test('a fast but possible set is accepted (two reps a second is the ceiling)', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['duration_seconds' => 10, 'total_reps' => 20, 'good_reps' => 20, 'bad_reps' => 0, 'rep_log' => []]))
        ->assertSuccessful();
});

test('a rule from a different exercise is rejected', function () {
    $user = User::factory()->onboarded()->create();
    $bench = Exercise::where('slug', 'flat-barbell-bench-press')->with('postureRules')->firstOrFail();

    $this->actingAs($user)->postJson('/api/posture-sessions', postureSetPayload('barbell-squat', ['posture_rule_id' => $bench->activePostureRule()->id]))
        ->assertStatus(422)->assertJsonValidationErrors('posture_rule_id');
});

test('saving needs a login', function () {
    $this->postJson('/api/posture-sessions', postureSetPayload())->assertUnauthorized();
});

test('what is stored never contains camera data', function () {
    $user = User::factory()->onboarded()->create();
    $payload = postureSetPayload() + ['frame' => 'data:image/png;base64,AAAA', 'landmarks' => [[0.1, 0.2]]];

    $this->actingAs($user)->postJson('/api/posture-sessions', $payload)->assertCreated();

    $row = PostureSession::firstOrFail()->getAttributes();
    expect(json_encode($row))->not->toContain('base64')->not->toContain('landmarks');
});
