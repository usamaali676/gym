<?php

use App\Models\AppSetting;
use App\Models\Badge;
use App\Models\Exercise;
use App\Models\Measurement;
use App\Models\MembershipPlan;
use App\Models\PostureRule;
use App\Models\PostureSession;
use App\Models\ProgressPhoto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedPosture();
    seedBadges();
    Storage::fake('local');
    Carbon::setTestNow('2026-10-12 09:00:00');
    $this->user = User::factory()->onboarded()->create();
    $this->h = fn () => apiAs($this->user);
});
afterEach(fn () => Carbon::setTestNow());

describe('measurements', function () {
    it('saves a day, merges partial updates and keeps the profile weight current', function () {
        $this->postJson('/api/v1/progress/measurements', ['measured_on' => '2026-10-12', 'weight_kg' => 80.5], ($this->h)())->assertCreated()->assertJsonPath('data.measurement.weight_kg', 80.5);
        $merged = $this->postJson('/api/v1/progress/measurements', ['measured_on' => '2026-10-12', 'waist_cm' => 84], ($this->h)())->assertCreated();

        expect(Measurement::count())->toBe(1)
            ->and($merged->json('data.measurement.weight_kg'))->toBe(80.5)
            ->and($merged->json('data.measurement.waist_cm'))->toBe(84)
            ->and($this->user->profile->fresh()->weight_kg)->toBe(80.5);
    });

    it('validates what comes in', function () {
        $post = fn (array $b) => $this->postJson('/api/v1/progress/measurements', $b, ($this->h)());

        $post(['measured_on' => '2026-10-12'])->assertStatus(422);                       // nothing measured
        $post(['measured_on' => '2026-10-20', 'weight_kg' => 80])->assertStatus(422);    // future
        $post(['measured_on' => '2026-10-12', 'weight_kg' => 5])->assertStatus(422);     // implausible
        $post(['measured_on' => '12/10/2026', 'weight_kg' => 80])->assertStatus(422);
        $post(['measured_on' => '2026-10-12', 'body_fat_pct' => 90])->assertStatus(422);
    });

    it('lists, summarises and deletes only my own', function () {
        foreach ([['2026-09-12', 82], ['2026-10-01', 81], ['2026-10-12', 80]] as [$d, $w]) {
            $this->postJson('/api/v1/progress/measurements', ['measured_on' => $d, 'weight_kg' => $w], ($this->h)());
        }
        $other = User::factory()->onboarded()->create();
        Measurement::create(['user_id' => $other->id, 'measured_on' => '2026-10-12', 'weight_kg' => 55]);

        $list = $this->getJson('/api/v1/progress/measurements', ($this->h)())->assertOk()->json('data');
        expect(collect($list)->pluck('weight_kg')->all())->toBe([80, 81, 82]);

        $summary = $this->getJson('/api/v1/progress/summary', ($this->h)())->assertOk()->json('data');
        expect($summary['weight_points'])->toHaveCount(3)->and($summary['change_30d_kg'])->toBe(-2)->and($summary['bmi'])->toBeGreaterThan(15);

        $foreign = Measurement::where('user_id', $other->id)->value('id');
        $this->deleteJson("/api/v1/progress/measurements/{$foreign}", [], ($this->h)())->assertNotFound();
        $mine = $list[0]['id'];
        $this->deleteJson("/api/v1/progress/measurements/{$mine}", [], ($this->h)())->assertOk();
        expect(Measurement::count())->toBe(3);
    });
});

describe('strength and records', function () {
    it('reports strength over time and records from completed workouts', function () {
        $series = fn () => $this->getJson('/api/v1/progress/strength/flat-barbell-bench-press', ($this->h)())->assertOk()->json('data.series');
        expect($series())->toBe([]);
        $this->getJson('/api/v1/progress/strength/no-such-lift', ($this->h)())->assertNotFound();

        foreach (['2026-10-05' => 60, '2026-10-12' => 65] as $date => $kg) {
            Carbon::setTestNow("{$date} 09:00:00");
            $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
            $this->postJson("/api/v1/workout-sessions/{$s['id']}/sets", ['session_exercise_id' => $s['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => $kg, 'reps' => 8], ($this->h)());
            $this->postJson("/api/v1/workout-sessions/{$s['id']}/finish", [], ($this->h)());
        }

        $points = $series();
        expect($points)->toHaveCount(2)->and($points[1]['top_weight'])->toBe(65)->and($points[1]['e1rm'])->toBeGreaterThan($points[0]['e1rm']);
        $records = $this->getJson('/api/v1/progress/records', ($this->h)())->assertOk()->json('data');
        expect($records)->toHaveCount(1)->and($records[0]['exercise']['slug'])->toBe('flat-barbell-bench-press')->and($records[0]['records']['max_weight']['value'])->toBe(65);
    });

    it('never mixes in another member\'s history', function () {
        $other = User::factory()->onboarded()->create();
        $s = $this->postJson('/api/v1/workout-sessions', [], apiAs($other))->json('data');
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/sets", ['session_exercise_id' => $s['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 100, 'reps' => 5], apiAs($other));
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/finish", [], apiAs($other));

        expect($this->getJson('/api/v1/progress/strength/flat-barbell-bench-press', ($this->h)())->json('data.series'))->toBe([])
            ->and($this->getJson('/api/v1/progress/records', ($this->h)())->json('data'))->toBe([]);
    });
});

describe('photos', function () {
    it('re-encodes uploads, hides them from everyone else and removes the file on delete', function () {
        $r = $this->post('/api/v1/progress/photos', ['photo' => realImage('selfie.png'), 'pose' => 'front', 'taken_on' => '2026-10-12'], ($this->h)() + ['Accept' => 'application/json']);
        $r->assertStatus(422); // tiny test image: the 200 px minimum protects against thumbnails

        $big = (function () {
            $img = imagecreatetruecolor(400, 500);
            imagefill($img, 0, 0, imagecolorallocate($img, 120, 90, 60));
            ob_start();
            imagepng($img);

            return realUpload('photo.png', ob_get_clean());
        })();
        $created = $this->post('/api/v1/progress/photos', ['photo' => $big, 'pose' => 'front', 'taken_on' => '2026-10-12', 'notes' => 'week 1'], ($this->h)() + ['Accept' => 'application/json'])->assertCreated();

        $photo = ProgressPhoto::firstOrFail();
        expect($photo->mime)->toBe('image/jpeg')->and($photo->path)->toMatch('#^progress/\d+/[0-9a-f-]{36}\.jpg$#')
            ->and($created->json('data.photo.pose'))->toBe('front')->and($created->json('data.photo.url'))->toBe("/api/v1/progress/photos/{$photo->id}");
        Storage::disk('local')->assertExists($photo->path);

        $this->get("/api/v1/progress/photos/{$photo->id}", ($this->h)())->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $stranger = User::factory()->onboarded()->create();
        $this->get("/api/v1/progress/photos/{$photo->id}", apiAs($stranger))->assertNotFound();
        $this->deleteJson("/api/v1/progress/photos/{$photo->id}", [], apiAs($stranger))->assertNotFound();
        $this->get("/api/v1/progress/photos/{$photo->id}")->assertUnauthorized();
        expect($this->getJson('/api/v1/progress/photos', apiAs($stranger))->json('data'))->toBe([]);

        $this->deleteJson("/api/v1/progress/photos/{$photo->id}", [], ($this->h)())->assertOk();
        Storage::disk('local')->assertMissing($photo->path);
    });

    it('rejects things that are not images', function () {
        $fake = realUpload('x.png', '<?php echo 1; ?>');

        $this->post('/api/v1/progress/photos', ['photo' => $fake, 'pose' => 'front', 'taken_on' => '2026-10-12'], ($this->h)() + ['Accept' => 'application/json'])->assertStatus(422);
        expect(ProgressPhoto::count())->toBe(0);
    });

    it('limits photos by plan', function () {
        $free = MembershipPlan::default();
        $free->limits = ['progress_photos' => 1];
        $free->save();
        $make = fn () => (function () {
            $img = imagecreatetruecolor(300, 300);
            ob_start();
            imagejpeg($img);

            return realUpload('p.jpg', ob_get_clean());
        })();

        $this->post('/api/v1/progress/photos', ['photo' => $make(), 'pose' => 'front', 'taken_on' => '2026-10-12'], ($this->h)() + ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/progress/photos', ['photo' => $make(), 'pose' => 'side', 'taken_on' => '2026-10-12'], ($this->h)() + ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('error.code', 'limit_reached');
    });
});

describe('achievements', function () {
    it('lists every badge with progress, and the level maths from the server', function () {
        $data = $this->getJson('/api/v1/achievements', ($this->h)())->assertOk()->json('data');

        expect($data['badges'])->toHaveCount(Badge::where('is_active', true)->count())
            ->and($data['badges'][0])->toHaveKeys(['slug', 'name', 'tier', 'earned', 'progress' => ['value', 'target', 'pct']])
            ->and($data['xp'])->toHaveKeys(['total', 'level', 'into', 'needed', 'pct'])
            ->and($data['earned_count'])->toBe(0);
    });

    it('awards a badge only on the server and shows it as earned afterwards', function () {
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/sets", ['session_exercise_id' => $s['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 40, 'reps' => 10], ($this->h)());
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/finish", [], ($this->h)())->assertOk();

        $data = $this->getJson('/api/v1/achievements', ($this->h)())->json('data');
        expect($data['earned_count'])->toBeGreaterThan(0)
            ->and(collect($data['badges'])->where('earned', true)->first()['awarded_at'])->not->toBeNull()
            ->and($data['xp']['total'])->toBeGreaterThan(0);
        // the same call twice never double-pays
        $xp = $this->user->fresh()->xp_total;
        $this->getJson('/api/v1/achievements', ($this->h)());
        expect($this->user->fresh()->xp_total)->toBe($xp);
    });

    it('offers no way to grant yourself XP or a badge', function () {
        foreach (['/api/v1/achievements', '/api/v1/me', '/api/v1/progress/measurements'] as $url) {
            $this->postJson($url, ['xp_total' => 99999, 'level' => 50, 'badge' => 'all'], ($this->h)());
        }
        $this->patchJson('/api/v1/me', ['name' => 'Same', 'email' => $this->user->email, 'xp_total' => 99999, 'level' => 50, 'role' => 'admin'], ($this->h)())->assertOk();

        $fresh = $this->user->fresh();
        expect($fresh->xp_total)->toBe(0)->and($fresh->level)->toBe(1)->and($fresh->isAdmin())->toBeFalse();
    });

    it('shows the leaderboard only for members who opted in and honours the dashboard switch', function () {
        $this->user->profile->update(['leaderboard_opt_in' => true]);
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');
        $this->postJson("/api/v1/workout-sessions/{$s['id']}/sets", ['session_exercise_id' => $s['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 40, 'reps' => 10], ($this->h)());
        $quiet = User::factory()->onboarded(['leaderboard_opt_in' => false])->create();
        $this->postJson('/api/v1/habits/water', ['amount_ml' => 250], apiAs($quiet)); // earns nothing, and never listed

        $board = $this->getJson('/api/v1/leaderboard', ($this->h)())->assertOk()->json('data');
        expect($board['enabled'])->toBeTrue()->and($board['opted_in'])->toBeTrue()->and($board['entries'])->toHaveCount(1)->and($board['entries'][0]['me'])->toBeTrue();

        AppSetting::create(['key' => 'features.leaderboard', 'value' => false]);
        expect($this->getJson('/api/v1/leaderboard', ($this->h)())->json('data'))->toMatchArray(['enabled' => false, 'entries' => []]);
    });
});

describe('posture coach', function () {
    it('lists exactly the exercises that have an active rule, with the rule versions', function () {
        $list = $this->getJson('/api/v1/posture/exercises', ($this->h)())->assertOk()->json('data');

        expect($list)->not->toBeEmpty()->and($list[0])->toHaveKeys(['exercise' => ['slug', 'name'], 'rule_version', 'tier', 'camera_view']);
        expect(collect($list)->pluck('exercise.slug')->all())->toEqualCanonicalizing(
            PostureRule::where('is_active', true)->with('exercise')->get()->pluck('exercise.slug')->unique()->all()
        );
    });

    it('serves the JSON rule for an exercise and refuses unsupported ones', function () {
        $slug = PostureRule::where('is_active', true)->first()->exercise->slug;

        $rule = $this->getJson("/api/v1/posture/rules/{$slug}", ($this->h)())->assertOk()->json('data');
        expect($rule['config'])->toHaveKeys(['schema', 'measures', 'checks'])->and($rule['rule_version'])->toBeGreaterThanOrEqual(1);
        $this->getJson('/api/v1/posture/rules/does-not-exist', ($this->h)())->assertNotFound();
        $unsupported = Exercise::published()->whereDoesntHave('postureRules')->value('slug');
        $this->getJson("/api/v1/posture/rules/{$unsupported}", ($this->h)())->assertNotFound()->assertJsonPath('error.message', 'The posture coach does not support this exercise yet.');
    });

    it('stores a numeric summary once, pays XP once and caps manipulated scores', function () {
        $rule = PostureRule::where('is_active', true)->first();
        $payload = [
            'client_uuid' => '7b4a5a3e-6c1f-4f8e-9d3c-1a2b3c4d5e6f', 'exercise_id' => $rule->exercise_id, 'posture_rule_id' => $rule->id, 'rule_version' => $rule->version,
            'camera_view' => $rule->camera_view->value, 'started_at' => now()->subMinutes(2)->toIso8601String(), 'duration_seconds' => 60,
            'total_reps' => 10, 'good_reps' => 8, 'bad_reps' => 2, 'form_score_avg' => 82, 'form_score_min' => 60, 'avg_fps' => 24.5,
        ];

        $first = $this->postJson('/api/v1/posture/sessions', $payload, ($this->h)())->assertCreated();
        $again = $this->postJson('/api/v1/posture/sessions', $payload, ($this->h)())->assertOk();

        expect($first->json('data.created'))->toBeTrue()->and($first->json('data.xp.total'))->toBeGreaterThan(0)
            ->and($again->json('data.created'))->toBeFalse()->and($again->json('data.xp.total'))->toBe(0)
            ->and(PostureSession::count())->toBe(1);
        $this->postJson('/api/v1/posture/sessions', ['client_uuid' => '11111111-2222-4333-8444-555555555555', 'good_reps' => 99, 'bad_reps' => 0, 'total_reps' => 10] + $payload, ($this->h)())->assertStatus(422);
        $this->postJson('/api/v1/posture/sessions', ['client_uuid' => '11111111-2222-4333-8444-555555555556'] + array_merge($payload, ['good_reps' => 3, 'bad_reps' => 3]), ($this->h)())->assertStatus(422);
    });

    it('does not let a second member reuse a session id', function () {
        $rule = PostureRule::where('is_active', true)->first();
        $payload = ['client_uuid' => '7b4a5a3e-6c1f-4f8e-9d3c-1a2b3c4d5e6f', 'exercise_id' => $rule->exercise_id, 'rule_version' => $rule->version, 'camera_view' => $rule->camera_view->value, 'started_at' => now()->subMinute()->toIso8601String(), 'duration_seconds' => 30, 'total_reps' => 5, 'good_reps' => 5, 'bad_reps' => 0, 'form_score_avg' => 90, 'form_score_min' => 80];

        $this->postJson('/api/v1/posture/sessions', $payload, ($this->h)())->assertCreated();
        $this->postJson('/api/v1/posture/sessions', $payload, apiAs(User::factory()->onboarded()->create()))->assertStatus(409);
    });

    it('is closed when the plan does not include the camera coach', function () {
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['posture_coach' => 1]);
        $free->save();

        $this->getJson('/api/v1/posture/exercises', ($this->h)())->assertForbidden()->assertJsonPath('error.details.required_entitlement', 'posture_coach');
        $this->postJson('/api/v1/posture/sessions', [], ($this->h)())->assertForbidden();
    });
});

describe('dashboard', function () {
    it('gathers the home screen in one call', function () {
        $d = $this->getJson('/api/v1/dashboard', ($this->h)())->assertOk()->json('data');

        expect($d['greeting_period'])->toBe('morning')
            ->and($d['workout']['day']['title'])->toContain('PUSH')->and($d['workout']['status'])->toBe('today')->and($d['workout']['open_session_id'])->toBeNull()
            ->and($d['week']['days'])->toHaveCount(7)->and($d['week']['target'])->toBe(5)
            ->and($d['xp'])->toHaveKeys(['total', 'level', 'pct'])
            ->and($d['habits']['water'])->toHaveKeys(['goal_ml', 'total_ml'])
            ->and($d['quote'])->not->toBeEmpty()
            ->and($d['membership']['plan_slug'])->toBe('free');
    });

    it('points at the unfinished workout so the app can offer to resume', function () {
        $s = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->json('data');

        expect($this->getJson('/api/v1/dashboard', ($this->h)())->json('data.workout'))->toMatchArray(['open_session_id' => $s['id'], 'status' => 'in_progress']);
    });

    it('needs a finished profile', function () {
        $this->getJson('/api/v1/dashboard', apiAs(User::factory()->create()))->assertStatus(409)->assertJsonPath('error.code', 'onboarding_required');
    });
});
