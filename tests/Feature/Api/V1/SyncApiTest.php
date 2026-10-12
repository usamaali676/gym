<?php

use App\Models\ContentChange;
use App\Models\DailyLog;
use App\Models\Food;
use App\Models\FoodLog;
use App\Models\IdempotencyKey;
use App\Models\Measurement;
use App\Models\MembershipPlan;
use App\Models\SessionSet;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\WaterLog;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPosture();
    seedFoods();
    seedBadges();
    Carbon::setTestNow('2026-10-12 09:00:00');
    $this->user = User::factory()->onboarded()->create();
    $this->h = fn () => apiAs($this->user);
});
afterEach(fn () => Carbon::setTestNow());

function op(string $type, array $payload, ?string $at = null, ?string $id = null): array
{
    return ['op_id' => $id ?? (string) Str::uuid(), 'type' => $type, 'payload' => $payload, 'client_created_at' => $at ?? now()->toIso8601String()];
}

function batch($test, array $ops, ?array $headers = null)
{
    return $test->postJson('/api/v1/sync/batch', ['operations' => $ops], $headers ?? ($test->h)());
}

describe('Idempotency-Key', function () {
    it('answers a retry with the stored response and does not run the action twice', function () {
        $body = ['amount_ml' => 500];
        $headers = ($this->h)() + ['Idempotency-Key' => 'water-0001-abcd'];

        $first = $this->postJson('/api/v1/habits/water', $body, $headers)->assertOk();
        $retry = $this->postJson('/api/v1/habits/water', $body, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        expect(WaterLog::count())->toBe(1)->and($retry->json())->toBe($first->json());
    });

    it('rejects the same key for a different request', function () {
        $headers = ($this->h)() + ['Idempotency-Key' => 'water-0002-abcd'];
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], $headers)->assertOk();

        $this->postJson('/api/v1/habits/water', ['amount_ml' => 250], $headers)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
        expect(WaterLog::count())->toBe(1);
    });

    it('scopes keys to the member, so keys cannot be probed across accounts', function () {
        $other = User::factory()->onboarded()->create();
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], ($this->h)() + ['Idempotency-Key' => 'shared-key-1234'])->assertOk();

        $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], apiAs($other) + ['Idempotency-Key' => 'shared-key-1234'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        expect(WaterLog::count())->toBe(2);
    });

    it('does not store failures that should be retried for real, but does store client errors', function () {
        $headers = ($this->h)() + ['Idempotency-Key' => 'bad-input-0001'];
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 1], $headers)->assertStatus(422);
        $replay = $this->postJson('/api/v1/habits/water', ['amount_ml' => 1], $headers)->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('error.code'))->toBe('validation_failed');
    });

    it('rejects a malformed key and ignores safe methods', function () {
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], ($this->h)() + ['Idempotency-Key' => 'x'])->assertStatus(422);
        $this->getJson('/api/v1/habits', ($this->h)() + ['Idempotency-Key' => 'get-key-123456'])->assertOk();
        expect(IdempotencyKey::count())->toBe(0);
    });

    it('lets an expired key be used again', function () {
        $headers = ($this->h)() + ['Idempotency-Key' => 'expiring-key-01'];
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], $headers)->assertOk();
        Carbon::setTestNow(now()->addHours(25));

        $this->postJson('/api/v1/habits/water', ['amount_ml' => 500], ($this->h)() + ['Idempotency-Key' => 'expiring-key-01'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        expect(WaterLog::count())->toBe(2);
    });
});

describe('offline batch', function () {
    it('replays a whole offline workout in order: start, sets, state, finish', function () {
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
        $row = $s['exercises'][0]['id'];
        $t = fn (int $min) => now()->addMinutes($min)->toIso8601String();
        Carbon::setTestNow('2026-10-12 10:00:00');

        $r = batch($this, [
            op('workout.set.log', ['session_id' => $s['id'], 'session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], $t(-50)),
            op('workout.set.log', ['session_id' => $s['id'], 'session_exercise_id' => $row, 'set_number' => 2, 'weight_kg' => 60, 'reps' => 7], $t(-48)),
            op('workout.state.save', ['session_id' => $s['id'], 'state' => ['idx' => 1, 'setIdx' => 0, 'phase' => 'work', 'saved_at' => '2026-10-12T09:50:00Z']], $t(-45)),
            op('workout.finish', ['session_id' => $s['id'], 'active_seconds' => 2400], $t(-10)),
        ])->assertOk();

        expect(collect($r->json('data.results'))->pluck('status')->all())->toBe(['applied', 'applied', 'applied', 'applied'])
            ->and(SessionSet::count())->toBe(2)->and(WorkoutSession::first()->status->value)->toBe('completed')
            ->and($r->json('data.results.0.totals.sets'))->toBe(1);
    });

    it('skips an exercise offline, harmlessly twice, and refuses it on someone else\'s workout', function () {
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
        $row = $s['exercises'][0]['id'];

        $r = batch($this, [op('workout.exercise.skip', ['session_id' => $s['id'], 'session_exercise_id' => $row])])->assertOk();
        expect($r->json('data.results.0.status'))->toBe('applied')
            ->and($this->getJson("/api/v1/workout-sessions/{$s['id']}", ($this->h)())->json('data.exercises.0.status'))->toBe('skipped');

        $again = batch($this, [op('workout.exercise.skip', ['session_id' => $s['id'], 'session_exercise_id' => $row])])->json('data.results.0');
        expect($again['status'])->toBe('applied');

        $stranger = User::factory()->onboarded()->create();
        $theirs = batch($this, [op('workout.exercise.skip', ['session_id' => $s['id'], 'session_exercise_id' => $row])], apiAs($stranger))->json('data.results.0');
        expect($theirs['status'])->toBe('rejected')->and($theirs['error']['code'])->toBe('not_found');

        $closing = batch($this, [op('workout.set.log', ['session_id' => $s['id'], 'session_exercise_id' => $s['exercises'][1]['id'], 'set_number' => 1, 'weight_kg' => 40, 'reps' => 8]), op('workout.finish', ['session_id' => $s['id']])])->assertOk();
        $closed = batch($this, [op('workout.exercise.skip', ['session_id' => $s['id'], 'session_exercise_id' => $s['exercises'][2]['id']])])->json('data.results.0');
        // finishing already marks what was not done as skipped, so a late skip is the state the member asked for
        expect($closed['status'])->toBe('applied')->and(WorkoutSession::find($s['id'])->status->value)->toBe('completed');
    });

    it('applies each operation once however often the batch is sent', function () {
        $food = Food::whereNull('user_id')->first();
        $ops = [
            op('food.log.create', ['food_id' => $food->id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams']),
            op('water.add', ['amount_ml' => 300]),
        ];

        $first = batch($this, $ops)->assertOk();
        $second = batch($this, $ops)->assertOk();

        expect(collect($first->json('data.results'))->pluck('status')->all())->toBe(['applied', 'applied'])
            ->and(collect($second->json('data.results'))->pluck('status')->all())->toBe(['duplicate', 'duplicate'])
            ->and($second->json('data.results.0.original_status'))->toBe('applied')
            ->and(FoodLog::count())->toBe(1)->and(WaterLog::count())->toBe(1)->and(SyncOperation::count())->toBe(2);
    });

    it('gives the same answer for a rejected operation on replay', function () {
        $bad = op('food.log.create', ['food_id' => 999999, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams']);

        $first = batch($this, [$bad])->json('data.results.0');
        $again = batch($this, [$bad])->json('data.results.0');

        expect($first['status'])->toBe('rejected')->and($first['error']['code'])->toBe('validation_failed')->and($again['status'])->toBe('duplicate')->and($again['original_status'])->toBe('rejected');
    });

    it('rejects a bad operation without stopping the ones after it', function () {
        $food = Food::whereNull('user_id')->first();

        $r = batch($this, [
            op('water.add', ['amount_ml' => 1]),
            op('mystery.thing', []),
            op('food.log.create', ['food_id' => $food->id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams']),
        ])->assertOk();

        expect(collect($r->json('data.results'))->pluck('status')->all())->toBe(['rejected', 'rejected', 'applied'])
            ->and($r->json('data.results.1.error.code'))->toBe('unknown_operation')
            ->and(FoodLog::count())->toBe(1);
    });

    it('validates the envelope', function () {
        $this->postJson('/api/v1/sync/batch', ['operations' => []], ($this->h)())->assertStatus(422);
        $this->postJson('/api/v1/sync/batch', ['operations' => [['op_id' => 'not-a-uuid', 'type' => 'water.add', 'payload' => []]]], ($this->h)())->assertStatus(422);
        $dup = (string) Str::uuid();
        $this->postJson('/api/v1/sync/batch', ['operations' => [op('water.add', ['amount_ml' => 250], null, $dup), op('water.add', ['amount_ml' => 250], null, $dup)]], ($this->h)())->assertStatus(422);
        $tooMany = array_map(fn () => op('water.add', ['amount_ml' => 250]), range(1, 101));
        $this->postJson('/api/v1/sync/batch', ['operations' => $tooMany], ($this->h)())->assertStatus(422);
    });

    it('rejects a set for a workout that was closed elsewhere', function () {
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/abandon", [], ($this->h)());

        $r = batch($this, [op('workout.set.log', ['session_id' => $s['id'], 'session_exercise_id' => $s['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8])]);

        expect($r->json('data.results.0.status'))->toBe('rejected')->and($r->json('data.results.0.error.code'))->toBe('session_closed')->and(SessionSet::count())->toBe(0);
    });

    it('cannot touch another member\'s workout', function () {
        $victim = User::factory()->onboarded()->create();
        $s = $this->postJson('/api/v1/workout-sessions', [], apiAs($victim))->json('data');

        $r = batch($this, [op('workout.set.log', ['session_id' => $s['id'], 'session_exercise_id' => $s['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8]), op('workout.finish', ['session_id' => $s['id']])]);

        expect(collect($r->json('data.results'))->pluck('error.code')->all())->toBe(['not_found', 'not_found'])->and(SessionSet::count())->toBe(0)->and(WorkoutSession::first()->status->value)->toBe('in_progress');
    });
});

describe('conflicts', function () {
    it('does not let an old measurement overwrite newer server data', function () {
        Measurement::create(['user_id' => $this->user->id, 'measured_on' => '2026-10-12', 'weight_kg' => 79]);
        $old = now()->subHour()->toIso8601String();

        $r = batch($this, [op('measurement.save', ['measured_on' => '2026-10-12', 'weight_kg' => 81], $old)])->json('data.results.0');

        expect($r['status'])->toBe('conflict')->and($r['server']['weight_kg'])->toBe(79)->and(Measurement::first()->weight_kg)->toBe(79.0);
    });

    it('applies the member\'s own version when they resubmit with a fresh timestamp', function () {
        Measurement::create(['user_id' => $this->user->id, 'measured_on' => '2026-10-12', 'weight_kg' => 79]);
        Carbon::setTestNow(now()->addMinutes(5));

        $r = batch($this, [op('measurement.save', ['measured_on' => '2026-10-12', 'weight_kg' => 81], now()->toIso8601String())])->json('data.results.0');

        expect($r['status'])->toBe('applied')->and(Measurement::first()->weight_kg)->toBe(81.0);
    });

    it('treats an identical value as no conflict', function () {
        Measurement::create(['user_id' => $this->user->id, 'measured_on' => '2026-10-12', 'weight_kg' => 79]);

        $r = batch($this, [op('measurement.save', ['measured_on' => '2026-10-12', 'weight_kg' => 79], now()->subHour()->toIso8601String())])->json('data.results.0');

        expect($r['status'])->toBe('applied');
    });

    it('keeps a newer habit entry made elsewhere', function () {
        $this->putJson('/api/v1/habits/sleep', ['sleep_hours' => 8], ($this->h)())->assertOk();

        $r = batch($this, [op('habit.sleep', ['sleep_hours' => 5], now()->subHour()->toIso8601String())])->json('data.results.0');

        expect($r['status'])->toBe('conflict')->and(DailyLog::first()->sleep_hours)->toBe(8.0);
    });

    it('keeps a set that was corrected later on the website', function () {
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
        $row = $s['exercises'][0]['id'];
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/sets", ['session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 70, 'reps' => 8], ($this->h)());

        $r = batch($this, [op('workout.set.log', ['session_id' => $s['id'], 'session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], now()->subHour()->toIso8601String())])->json('data.results.0');

        expect($r['status'])->toBe('conflict')->and($r['server']['weight_kg'])->toBe(70)->and(SessionSet::first()->weight_kg)->toEqual(70.0);
    });

    it('stores an older queued entry when the server has nothing newer', function () {
        $r = batch($this, [op('habit.creatine', ['taken' => true, 'date' => '2026-10-10'], now()->subDay()->toIso8601String())])->json('data.results.0');

        expect($r['status'])->toBe('applied')->and(DailyLog::first()->creatine_taken)->toBeTrue();
    });
});

describe('membership gate', function () {
    it('applies the same paywall to queued operations as to live ones', function () {
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['nutrition' => 1, 'posture_coach' => 1]);
        $free->save();
        $food = Food::whereNull('user_id')->first();

        $r = batch($this, [
            op('food.log.create', ['food_id' => $food->id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams']),
            op('water.add', ['amount_ml' => 250]),
            op('posture.session.save', []),
            op('habit.creatine', ['taken' => true]),
        ])->json('data.results');

        expect(collect($r)->pluck('status')->all())->toBe(['rejected', 'rejected', 'rejected', 'applied'])
            ->and($r[0]['error']['code'])->toBe('entitlement_required')
            ->and(FoodLog::count())->toBe(0)->and(WaterLog::count())->toBe(0);
    });

    it('requires authentication and a finished profile', function () {
        $this->postJson('/api/v1/sync/batch', ['operations' => [op('water.add', ['amount_ml' => 250])]])->assertUnauthorized();
        $this->postJson('/api/v1/sync/batch', ['operations' => [op('water.add', ['amount_ml' => 250])]], apiAs(User::factory()->create()))->assertStatus(409);
    });
});

describe('maintenance', function () {
    it('prunes expired idempotency keys, old operations and old feed entries but keeps a resume point', function () {
        IdempotencyKey::create(['user_id' => $this->user->id, 'key' => 'old-key-12345', 'fingerprint' => str_repeat('a', 64), 'response_code' => 200, 'response_body' => '{}', 'expires_at' => now()->subHour()]);
        IdempotencyKey::create(['user_id' => $this->user->id, 'key' => 'new-key-12345', 'fingerprint' => str_repeat('b', 64), 'response_code' => 200, 'response_body' => '{}', 'expires_at' => now()->addHour()]);
        SyncOperation::create(['user_id' => $this->user->id, 'op_id' => (string) Str::uuid(), 'type' => 'water.add', 'status' => 'applied', 'created_at' => now()->subDays(40)]);
        ContentChange::query()->update(['created_at' => now()->subDays(200)]);
        $latest = ContentChange::latestId();

        $this->artisan('api:prune')->assertSuccessful();

        expect(IdempotencyKey::pluck('key')->all())->toBe(['new-key-12345'])->and(SyncOperation::count())->toBe(0)
            ->and(ContentChange::count())->toBe(1)->and(ContentChange::latestId())->toBe($latest);
    });
});
