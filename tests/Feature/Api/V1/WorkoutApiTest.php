<?php

use App\Models\Exercise;
use App\Models\MembershipPlan;
use App\Models\PersonalRecord;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedLibrary();
    seedBadges();
    Carbon::setTestNow('2026-10-12 09:00:00'); // Monday: push / chest
    $this->user = User::factory()->onboarded(['plan_started_on' => '2026-10-05'])->create();
    $this->h = fn () => apiAs($this->user);
});
afterEach(fn () => Carbon::setTestNow());

function startWorkout($test, array $body = [], ?User $user = null): array
{
    $response = $test->postJson('/api/v1/workout-sessions', $body, $user ? apiAs($user) : ($test->h)());

    return [$response, $response->json('data')];
}

describe('plan', function () {
    it('shows the week with a status for every day', function () {
        $r = $this->getJson('/api/v1/plan/week', ($this->h)())->assertOk();

        expect($r->json('data.days'))->toHaveCount(7)
            ->and($r->json('data.monday'))->toBe('2026-10-12')
            ->and($r->json('data.week_number'))->toBe(2)
            ->and($r->json('data.days.0.status'))->toBe('today')
            ->and($r->json('data.days.0.day.title'))->toContain('PUSH')
            ->and($r->json('data.progress'))->toBe(['done' => 0, 'target' => 5])
            ->and($r->json('data.program.name'))->not->toBeEmpty();
    });

    it('returns a day with its exercises, ranges and rest times from the plan data', function () {
        $r = $this->getJson('/api/v1/plan/days/1', ($this->h)())->assertOk();

        expect($r->json('data.exercises'))->toHaveCount(7)
            ->and($r->json('data.exercises.0.exercise.slug'))->toBe('flat-barbell-bench-press')
            ->and($r->json('data.exercises.0.reps_min'))->toBe(6)
            ->and($r->json('data.exercises.0.rest_seconds'))->toBe(150)
            ->and($r->json('data.can_act'))->toBeTrue()
            ->and($r->json('data.estimated_minutes'))->toBeGreaterThan(30);
        $this->getJson('/api/v1/plan/days/9', ($this->h)())->assertNotFound();
        $this->getJson('/api/v1/plan/days/1?date=nonsense', ($this->h)())->assertStatus(422);
    });

    it('marks a past day done or skipped and lets a skip be undone', function () {
        $this->postJson('/api/v1/plan/days/1/skip', ['date' => '2026-10-12'], ($this->h)())->assertOk();
        expect(WorkoutSession::first()->status->value)->toBe('skipped');

        $this->deleteJson('/api/v1/plan/days/1/skip', ['date' => '2026-10-12'], ($this->h)())->assertOk();
        $this->postJson('/api/v1/plan/days/1/complete', ['date' => '2026-10-12'], ($this->h)())->assertOk()->assertJsonPath('data.status', 'completed');
        expect($this->getJson('/api/v1/plan/week', ($this->h)())->json('data.days.0.status'))->toBe('completed');
    });

    it('swaps an exercise, and only on the program the member follows', function () {
        $item = PlanDayExercise::firstOrFail();
        $alts = $this->getJson("/api/v1/plan-items/{$item->id}/alternatives", ($this->h)())->assertOk()->json('data');
        expect($alts)->not->toBeEmpty();

        $this->postJson("/api/v1/plan-items/{$item->id}/swap", ['exercise_id' => $alts[0]['id'], 'permanent' => true], ($this->h)())->assertCreated()->assertJsonPath('data.permanent', true);
        $day = $this->getJson('/api/v1/plan/days/1', ($this->h)())->json('data.exercises.0');
        expect($day['swapped'])->toBeTrue()->and($day['exercise']['id'])->toBe($alts[0]['id']);

        $this->deleteJson("/api/v1/plan-items/{$item->id}/swap", [], ($this->h)())->assertOk();
        expect($this->getJson('/api/v1/plan/days/1', ($this->h)())->json('data.exercises.0.swapped'))->toBeFalse();

        $other = Plan::create(['slug' => 'o', 'name' => 'O', 'status' => 'published', 'version' => 1]);
        $foreign = PlanDayExercise::create(['plan_day_id' => PlanDay::create(['plan_id' => $other->id, 'day_of_week' => 1, 'slug' => 'x', 'name' => 'X', 'session_type' => 'push', 'label' => 'X', 'focus' => 'X'])->id, 'exercise_id' => Exercise::first()->id, 'position' => 1, 'sets' => 3, 'reps_min' => 5, 'reps_max' => 8, 'focus' => 'x']);
        $this->getJson("/api/v1/plan-items/{$foreign->id}/alternatives", ($this->h)())->assertNotFound();
        $this->postJson("/api/v1/plan-items/{$item->id}/swap", ['exercise_id' => 999999], ($this->h)())->assertStatus(422);
    });
});

describe('running a workout', function () {
    it('starts today\'s workout with the plan snapshot and a server time for timer maths', function () {
        [$response, $data] = startWorkout($this);

        $response->assertCreated();
        expect($data['status'])->toBe('in_progress')
            ->and($data['exercises'])->toHaveCount(7)
            ->and($data['exercises'][0]['slug'])->toBe('flat-barbell-bench-press')
            ->and($data['exercises'][0]['target_sets'])->toBe(3)
            ->and($data['exercises'][0]['rest_seconds'])->toBe(150)
            ->and($data['exercises'][0]['suggested']['reason'])->not->toBeEmpty()
            ->and($data['started_at'])->not->toBeNull()
            ->and($data['server_time'])->not->toBeNull()
            ->and($data['client_state'])->toBeNull();
    });

    it('resumes the same session instead of starting a second one', function () {
        [, $first] = startWorkout($this);
        [$again, $second] = startWorkout($this);

        $again->assertOk();
        expect($second['id'])->toBe($first['id'])->and(WorkoutSession::count())->toBe(1);
        $this->getJson('/api/v1/workout-sessions/active', ($this->h)())->assertOk()->assertJsonPath('data.id', $first['id']);
    });

    it('has no active workout until one starts', function () {
        $this->getJson('/api/v1/workout-sessions/active', ($this->h)())->assertOk()->assertJsonPath('data', null);
    });

    it('refuses a future day and a rest day', function () {
        $this->postJson('/api/v1/workout-sessions', ['weekday' => 2, 'date' => '2026-10-13'], ($this->h)())->assertStatus(422); // future
        $this->postJson('/api/v1/workout-sessions', ['weekday' => 7, 'date' => '2026-10-11'], ($this->h)())->assertStatus(422); // rest day
        $this->postJson('/api/v1/workout-sessions', ['weekday' => 9], ($this->h)())->assertStatus(422);
        expect(WorkoutSession::count())->toBe(0);
    });

    it('refuses to start a different day while one is open', function () {
        [, $first] = startWorkout($this);
        Carbon::setTestNow('2026-10-13 09:00:00');

        $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->assertStatus(409)->assertJsonPath('error.code', 'session_in_progress')->assertJsonPath('error.details.session_id', $first['id']);
        expect(WorkoutSession::count())->toBe(1);
    });

    it('says so when today is not a training day', function () {
        Carbon::setTestNow('2026-10-17 09:00:00'); // Saturday = rest in the plan

        $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->assertStatus(422)->assertJsonPath('error.code', 'no_workout_today');
    });

    it('logs sets, awards XP once, detects a PR and updates exercise status', function () {
        [, $data] = startWorkout($this);
        $row = $data['exercises'][0];

        $set = $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)())
            ->assertCreated()->assertJsonPath('data.set.weight_kg', 60)->assertJsonPath('data.is_pr', false); // the first result is a baseline, not a record
        expect($set->json('data.xp'))->toBeGreaterThan(0)->and($set->json('data.totals.sets'))->toBe(1);

        // a retry of the same set corrects it, never duplicates it, and pays nothing twice
        $retry = $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row['id'], 'set_number' => 1, 'weight_kg' => 62.5, 'reps' => 8], ($this->h)())->assertCreated();
        expect($retry->json('data.xp'))->toBe(0)->and(SessionSet::count())->toBe(1)->and(SessionSet::first()->weight_kg)->toEqual(62.5);

        $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row['id'], 'set_number' => 2, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->assertCreated();
        $heavier = $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row['id'], 'set_number' => 3, 'weight_kg' => 70, 'reps' => 8], ($this->h)())->assertCreated();
        expect($heavier->json('data.exercise_status'))->toBe('done')->and($heavier->json('data.is_pr'))->toBeFalse(); // no earlier workout to beat yet
    });

    it('flags a personal record against an earlier workout', function () {
        Carbon::setTestNow('2026-10-05 09:00:00');
        [, $first] = startWorkout($this);
        $this->postJson("/api/v1/workout-sessions/{$first['id']}/sets", ['session_exercise_id' => $first['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->assertCreated();
        $this->postJson("/api/v1/workout-sessions/{$first['id']}/finish", [], ($this->h)())->assertOk();

        Carbon::setTestNow('2026-10-12 09:00:00');
        [, $second] = startWorkout($this);
        $weaker = $this->postJson("/api/v1/workout-sessions/{$second['id']}/sets", ['session_exercise_id' => $second['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 50, 'reps' => 8], ($this->h)())->assertCreated();
        $stronger = $this->postJson("/api/v1/workout-sessions/{$second['id']}/sets", ['session_exercise_id' => $second['exercises'][0]['id'], 'set_number' => 2, 'weight_kg' => 65, 'reps' => 8], ($this->h)())->assertCreated();

        expect($weaker->json('data.is_pr'))->toBeFalse()
            ->and($stronger->json('data.is_pr'))->toBeTrue()
            ->and($stronger->json('data.personal_records.0.previous'))->toBeGreaterThan(0)
            ->and($stronger->json('data.xp'))->toBeGreaterThan($weaker->json('data.xp'));
    });

    it('validates a set exactly like the website does', function () {
        [, $data] = startWorkout($this);
        $row = $data['exercises'][0]['id'];
        $url = "/api/v1/workout-sessions/{$data['id']}/sets";

        $this->postJson($url, ['session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 60], ($this->h)())->assertStatus(422)->assertJsonPath('error.details.reps.0', 'Log the reps or the time held.');
        $this->postJson($url, ['session_exercise_id' => $row, 'set_number' => 0, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->assertStatus(422);
        $this->postJson($url, ['session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 5000, 'reps' => 8], ($this->h)())->assertStatus(422);
        $this->postJson($url, ['session_exercise_id' => 999999, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->assertNotFound();
    });

    it('deletes a set and rolls the totals back', function () {
        [, $data] = startWorkout($this);
        $row = $data['exercises'][0]['id'];
        $setId = $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->json('data.set.id');

        $this->deleteJson("/api/v1/workout-sessions/{$data['id']}/sets/{$setId}", [], ($this->h)())->assertOk();

        expect(SessionSet::count())->toBe(0)->and(PersonalRecord::count())->toBe(0);
        $this->deleteJson("/api/v1/workout-sessions/{$data['id']}/sets/{$setId}", [], ($this->h)())->assertNotFound();
    });

    it('stores resume state and ignores a late, older save', function () {
        [, $data] = startWorkout($this);
        $url = "/api/v1/workout-sessions/{$data['id']}/state";
        $newer = ['idx' => 2, 'setIdx' => 1, 'phase' => 'rest', 'rest_ends_at' => '2026-10-12T09:05:00Z', 'saved_at' => '2026-10-12T09:03:00Z'];
        $older = ['idx' => 1, 'setIdx' => 0, 'phase' => 'work', 'saved_at' => '2026-10-12T09:01:00Z'];

        $this->putJson($url, ['state' => $newer], ($this->h)())->assertOk()->assertJsonPath('data.applied', true);
        $this->putJson($url, ['state' => $older], ($this->h)())->assertOk()->assertJsonPath('data.applied', false)->assertJsonPath('data.client_state.idx', 2);

        $resumed = $this->getJson("/api/v1/workout-sessions/{$data['id']}", ($this->h)())->json('data.client_state');
        expect($resumed['phase'])->toBe('rest')->and($resumed['rest_ends_at'])->toBe('2026-10-12T09:05:00Z');
        $this->putJson($url, ['state' => ['idx' => 1, 'setIdx' => 0, 'phase' => 'flying']], ($this->h)())->assertStatus(422);
        $this->putJson($url, ['state' => ['idx' => 1, 'setIdx' => 0, 'phase' => 'work', 'blob' => str_repeat('x', 5000)]], ($this->h)())->assertStatus(422);
    });

    it('skips and swaps an exercise during the session', function () {
        [, $data] = startWorkout($this);
        $row = $data['exercises'][1];

        $this->postJson("/api/v1/workout-sessions/{$data['id']}/exercises/{$row['id']}/skip", [], ($this->h)())->assertOk();
        $alts = $this->getJson("/api/v1/workout-sessions/{$data['id']}/exercises/{$data['exercises'][2]['id']}/alternatives", ($this->h)())->assertOk()->json('data');
        $swapped = $this->postJson("/api/v1/workout-sessions/{$data['id']}/exercises/{$data['exercises'][2]['id']}/swap", ['exercise_id' => $alts[0]['id']], ($this->h)())->assertOk()->json('data');

        expect($swapped['exercises'][1]['status'])->toBe('skipped')
            ->and($swapped['exercises'][2]['exercise_id'])->toBe($alts[0]['id'])
            ->and($swapped['exercises'][2]['swapped_from'])->not->toBeNull();
    });

    it('finishes with a full summary, and finishing again changes nothing', function () {
        [, $data] = startWorkout($this);
        $row = $data['exercises'][0]['id'];
        $this->postJson("/api/v1/workout-sessions/{$data['id']}/finish", [], ($this->h)())->assertStatus(422); // nothing logged
        foreach ([1, 2, 3] as $n) {
            $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row, 'set_number' => $n, 'weight_kg' => 60 + ($n === 3 ? 10 : 0), 'reps' => 8], ($this->h)());
        }

        Carbon::setTestNow('2026-10-12 10:00:00'); // an hour later: the claimed active time must fit inside the elapsed time
        $this->postJson("/api/v1/workout-sessions/{$data['id']}/finish", ['active_seconds' => 3300], ($this->h)())->assertOk()->assertJsonPath('data.status', 'completed');
        $xpAfter = $this->user->fresh()->xp_total;
        $this->postJson("/api/v1/workout-sessions/{$data['id']}/finish", ['active_seconds' => 3300], ($this->h)())->assertOk();
        expect($this->user->fresh()->xp_total)->toBe($xpAfter)->and($this->user->fresh()->workout_streak)->toBe(1);

        $summary = $this->getJson("/api/v1/workout-sessions/{$data['id']}/summary", ($this->h)())->assertOk()->json('data');
        expect($summary['session']['active_seconds'])->toBe(3300)
            ->and($summary['session']['sets_completed'])->toBe(3)
            ->and($summary['exercises_done'])->toBe(1)
            ->and($summary['personal_records'])->toBe([])
            ->and($summary['streak'])->toBe(1)
            ->and($summary['xp_breakdown'])->not->toBeEmpty()
            ->and($summary['exercises'][0]['sets'])->toHaveCount(3);

        // it can no longer be changed
        $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row, 'set_number' => 4, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->assertStatus(409);
        $this->getJson('/api/v1/workout-sessions/active', ($this->h)())->assertJsonPath('data', null);
    });

    it('does not believe an active time longer than the workout lasted', function () {
        [, $data] = startWorkout($this);
        $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $data['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)());
        Carbon::setTestNow('2026-10-12 09:30:00');

        $this->postJson("/api/v1/workout-sessions/{$data['id']}/finish", ['active_seconds' => 20000], ($this->h)())->assertOk();

        expect(WorkoutSession::first()->active_seconds)->toBeLessThanOrEqual(1860); // elapsed time plus one minute of slack
    });

    it('abandons a workout, which then no longer counts as open', function () {
        [, $data] = startWorkout($this);

        $this->postJson("/api/v1/workout-sessions/{$data['id']}/abandon", [], ($this->h)())->assertOk();

        expect(WorkoutSession::first()->status->value)->toBe('abandoned');
        $this->getJson("/api/v1/workout-sessions/{$data['id']}/summary", ($this->h)())->assertStatus(409)->assertJsonPath('error.code', 'session_not_completed');
        // the day can be trained again
        [$restart, $new] = startWorkout($this);
        $restart->assertCreated();
        expect($new['status'])->toBe('in_progress')->and($new['exercises'][0]['sets'])->toBe([]);
    });

    it('lists history newest first with cursor pagination and keeps old records readable', function () {
        foreach ([[1, '2026-10-05'], [1, '2026-10-12']] as [$weekday, $date]) {
            Carbon::setTestNow("{$date} 09:00:00");
            [, $data] = startWorkout($this);
            $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $data['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 50, 'reps' => 10], ($this->h)());
            $this->postJson("/api/v1/workout-sessions/{$data['id']}/finish", [], ($this->h)());
        }

        $page = $this->getJson('/api/v1/workout-sessions?per_page=1', ($this->h)())->assertOk();
        expect($page->json('data'))->toHaveCount(1)->and($page->json('data.0.scheduled_for'))->toBe('2026-10-12')->and($page->json('meta.has_more'))->toBeTrue();

        $next = $this->getJson('/api/v1/workout-sessions?per_page=1&cursor='.$page->json('meta.next_cursor'), ($this->h)())->assertOk();
        expect($next->json('data.0.scheduled_for'))->toBe('2026-10-05')->and($next->json('meta.has_more'))->toBeFalse();

        // an admin retiring an exercise or editing the plan must not change what was done
        $slug = $page->json('data.0.title');
        Exercise::where('slug', 'flat-barbell-bench-press')->update(['is_active' => false]);
        $past = $this->getJson('/api/v1/workout-sessions/'.$page->json('data.0.id'), ($this->h)())->assertOk();
        expect($past->json('data.exercises.0.name'))->toContain('Bench Press')->and($past->json('data.exercises.0.sets.0.weight_kg'))->toEqual(50)->and($slug)->not->toBeEmpty();
    });
});

describe('isolation', function () {
    it('treats another member\'s workout as not found everywhere', function () {
        [, $data] = startWorkout($this);
        $row = $data['exercises'][0]['id'];
        $setId = $this->postJson("/api/v1/workout-sessions/{$data['id']}/sets", ['session_exercise_id' => $row, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->json('data.set.id');
        $spy = User::factory()->onboarded()->create();
        $h = apiAs($spy);
        $id = $data['id'];

        $this->getJson("/api/v1/workout-sessions/{$id}", $h)->assertNotFound();
        $this->getJson("/api/v1/workout-sessions/{$id}/summary", $h)->assertNotFound();
        $this->putJson("/api/v1/workout-sessions/{$id}/state", ['state' => ['idx' => 0, 'setIdx' => 0, 'phase' => 'work']], $h)->assertNotFound();
        $this->postJson("/api/v1/workout-sessions/{$id}/sets", ['session_exercise_id' => $row, 'set_number' => 2, 'weight_kg' => 1, 'reps' => 1], $h)->assertNotFound();
        $this->deleteJson("/api/v1/workout-sessions/{$id}/sets/{$setId}", [], $h)->assertNotFound();
        $this->postJson("/api/v1/workout-sessions/{$id}/finish", [], $h)->assertNotFound();
        $this->postJson("/api/v1/workout-sessions/{$id}/abandon", [], $h)->assertNotFound();
        $this->postJson("/api/v1/workout-sessions/{$id}/exercises/{$row}/skip", [], $h)->assertNotFound();
        expect($this->getJson('/api/v1/workout-sessions', $h)->json('data'))->toBe([]);
        expect(SessionSet::count())->toBe(1);
    });
});

describe('membership gate', function () {
    it('closes every workout route once the plan no longer includes workouts', function () {
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['workouts' => 1]);
        $free->save();

        foreach ([['get', '/api/v1/plan/week'], ['get', '/api/v1/plan/days/1'], ['post', '/api/v1/workout-sessions'], ['get', '/api/v1/workout-sessions/active']] as [$verb, $url]) {
            ($verb === 'post' ? $this->postJson($url, [], ($this->h)()) : $this->getJson($url, ($this->h)()))->assertForbidden()->assertJsonPath('error.code', 'entitlement_required')->assertJsonPath('error.details.required_entitlement', 'workouts');
        }
        // the dashboard still works and shows what the plan includes
        $this->getJson('/api/v1/dashboard', ($this->h)())->assertOk()->assertJsonPath('data.membership.entitlements.workouts', false);
    });
});
