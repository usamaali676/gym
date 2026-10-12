<?php

use App\Models\Announcement;
use App\Models\Banner;
use App\Models\ContentChange;
use App\Models\Exercise;
use App\Models\ExerciseMedia;
use App\Models\Food;
use App\Models\HelpArticle;
use App\Models\MembershipPlan;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use App\Models\ProgramPhase;
use App\Models\User;
use App\Services\Membership\MembershipService;
use App\Support\Entitlement;
use App\Support\MediaUrls;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    seedPosture();
    $this->user = User::factory()->onboarded()->create();
    $this->h = fn () => apiAs($this->user);
});

function premiumExercise(array $o = []): Exercise
{
    return Exercise::factory()->premium()->create($o + ['name' => 'Secret Curl', 'slug' => 'secret-curl', 'muscle_group' => 'biceps']);
}

function grantPremium(User $user, string $key = 'library.premium'): void
{
    $plan = MembershipPlan::factory()->granting(...collect([$key])->map(fn ($k) => Entitlement::from($k))->all())->create(['slug' => 'prem-'.$key]);
    app(MembershipService::class)->grant($user, $plan, null);
}

describe('exercise library', function () {
    it('requires a token', function () {
        $this->getJson('/api/v1/exercises')->assertUnauthorized();
        $this->getJson('/api/v1/exercises/barbell-bench-press')->assertUnauthorized();
    });

    it('lists published exercises with pagination metadata', function () {
        $response = $this->getJson('/api/v1/exercises?per_page=10', ($this->h)())->assertOk();

        expect($response->json('data'))->toHaveCount(10)
            ->and($response->json('meta.total'))->toBe(Exercise::published()->count())
            ->and($response->json('meta.per_page'))->toBe(10);
        $response->assertJsonStructure(['data' => [['id', 'slug', 'name', 'kind', 'muscle_group', 'equipment', 'locked', 'media', 'posture' => ['supported']]], 'links', 'meta']);
    });

    it('never lists drafts or archived exercises', function () {
        Exercise::factory()->draft()->create(['slug' => 'hidden-draft', 'name' => 'Hidden Draft']);
        $archived = Exercise::factory()->create(['slug' => 'gone', 'name' => 'Gone']);
        $archived->update(['is_active' => false]); // the website's switch archives it

        expect($archived->fresh()->status)->toBe('archived');
        $names = collect($this->getJson('/api/v1/exercises?per_page=100&q=Hidden', ($this->h)())->json('data'))->pluck('slug')->all();
        expect($names)->toBe([]);
        $this->getJson('/api/v1/exercises/hidden-draft', ($this->h)())->assertNotFound();
        $this->getJson('/api/v1/exercises/gone', ($this->h)())->assertNotFound();
    });

    it('filters and searches', function () {
        $chest = $this->getJson('/api/v1/exercises?muscle=chest&per_page=100', ($this->h)())->json('data');
        expect($chest)->not->toBeEmpty()->and(collect($chest)->pluck('muscle_group')->unique()->all())->toBe(['chest']);

        $found = $this->getJson('/api/v1/exercises?q=bench&per_page=100', ($this->h)())->json('data');
        expect(collect($found)->every(fn ($e) => str_contains(strtolower($e['name'].$e['slug']), 'bench')))->toBeTrue();

        $this->getJson('/api/v1/exercises?muscle=wings', ($this->h)())->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    });

    it('returns the full detail for a free exercise, including posture-coach support', function () {
        $slug = Exercise::published()->whereHas('postureRules')->value('slug');

        $this->getJson("/api/v1/exercises/{$slug}", ($this->h)())->assertOk()
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.posture.supported', true)
            ->assertJsonStructure(['data' => ['description', 'form_cues', 'common_mistakes', 'rest' => ['default_seconds'], 'target_regions', 'demo_key']]);
    });

    it('shows a premium exercise as a teaser until the plan includes it', function () {
        $exercise = premiumExercise(['description' => 'Secret technique', 'form_cues' => ['hidden cue']]);
        ExerciseMedia::create(['exercise_id' => $exercise->id, 'kind' => 'demo_video', 'view' => 'side', 'disk' => 'media', 'path' => 'x.mp4', 'mime' => 'video/mp4', 'bytes' => 3, 'checksum' => str_repeat('a', 64)]);
        // restrict the free floor so the premium key is really absent
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['library.premium' => 1]);
        $free->save();

        $teaser = $this->getJson('/api/v1/exercises/secret-curl', ($this->h)())->assertOk()->assertJsonPath('data.locked', true)->json('data');
        expect($teaser)->not->toHaveKeys(['description', 'form_cues', 'media', 'target_regions'])
            ->and(json_encode($teaser))->not->toContain('Secret technique')->not->toContain('hidden cue');

        grantPremium($this->user);
        $full = $this->getJson('/api/v1/exercises/secret-curl', ($this->h)())->assertOk()->assertJsonPath('data.locked', false)->json('data');
        expect($full['description'])->toBe('Secret technique')->and($full['media'])->toHaveCount(1);
    });

    it('suggests alternatives for the same muscle group', function () {
        $slug = Exercise::published()->where('muscle_group', 'chest')->where('kind', 'plan')->value('slug');

        $response = $this->getJson("/api/v1/exercises/{$slug}/alternatives", ($this->h)())->assertOk();
        expect(collect($response->json('data'))->pluck('slug')->all())->not->toContain($slug)
            ->and(collect($response->json('data'))->pluck('muscle_group')->unique()->all())->toBe(['chest']);
    });
});

describe('programs', function () {
    it('lists the default program and returns the full weekly template', function () {
        $list = $this->getJson('/api/v1/programs', ($this->h)())->assertOk();
        expect($list->json('data'))->toHaveCount(1);
        $id = $list->json('data.0.id');
        $list->assertJsonPath('data.0.is_selected', true)->assertJsonPath('data.0.training_days', 5)->assertJsonPath('data.0.locked', false);

        $detail = $this->getJson("/api/v1/programs/{$id}", ($this->h)())->assertOk();
        expect($detail->json('data.days'))->toHaveCount(7)
            ->and(collect($detail->json('data.days'))->where('is_rest', true))->toHaveCount(2)
            ->and($detail->json('data.phases'))->toBe([]);
        $first = collect($detail->json('data.days'))->firstWhere('day_of_week', 1);
        expect($first['exercises'])->not->toBeEmpty()
            ->and($first['exercises'][0])->toHaveKeys(['exercise_slug', 'sets', 'reps_label', 'rest_seconds'])
            ->and($first['estimated_minutes'])->toBeGreaterThan(0);
    });

    it('exposes sprints and phases only once an admin publishes them', function () {
        $plan = Plan::default();
        ProgramPhase::create(['plan_id' => $plan->id, 'kind' => 'sprint', 'name' => 'Foundation', 'starts_week' => 1, 'ends_week' => 4, 'sort_order' => 1, 'status' => 'published']);
        ProgramPhase::create(['plan_id' => $plan->id, 'kind' => 'phase', 'name' => 'Secret draft', 'starts_week' => 5, 'sort_order' => 2, 'status' => 'draft']);

        $phases = $this->getJson("/api/v1/programs/{$plan->id}", ($this->h)())->json('data.phases');

        expect($phases)->toHaveCount(1)->and($phases[0]['name'])->toBe('Foundation')->and($phases[0]['ends_week'])->toBe(4);
    });

    it('keeps drafts invisible', function () {
        $draft = Plan::create(['slug' => 'next', 'name' => 'Next program', 'status' => 'draft', 'version' => 1]);

        expect(collect($this->getJson('/api/v1/programs', ($this->h)())->json('data'))->pluck('id')->all())->not->toContain($draft->id);
        $this->getJson("/api/v1/programs/{$draft->id}", ($this->h)())->assertNotFound();
    });

    it('refuses a premium program the plan does not include, in the list as a card and in detail as a 403', function () {
        $premium = Plan::create(['slug' => 'elite', 'name' => 'Elite', 'status' => 'published', 'version' => 1, 'required_entitlement' => 'programs.premium']);
        $day = PlanDay::create(['plan_id' => $premium->id, 'day_of_week' => 1, 'slug' => 'mon', 'name' => 'Monday', 'session_type' => 'push', 'label' => 'PUSH', 'focus' => 'Chest', 'is_rest' => false, 'description' => 'SECRET DAY']);
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['programs.premium' => 1]);
        $free->save();

        $card = collect($this->getJson('/api/v1/programs', ($this->h)())->json('data'))->firstWhere('slug', 'elite');
        expect($card['locked'])->toBeTrue();
        $this->getJson("/api/v1/programs/{$premium->id}", ($this->h)())->assertForbidden()->assertJsonPath('error.code', 'entitlement_required');
        $this->postJson('/api/v1/me/program', ['program_id' => $premium->id], ($this->h)())->assertForbidden();

        grantPremium($this->user, 'programs.premium');
        $this->getJson("/api/v1/programs/{$premium->id}", ($this->h)())->assertOk()->assertJsonPath('data.days.0.description', 'SECRET DAY');
    });

    it('lets a member switch program and restarts the week count', function () {
        $other = Plan::create(['slug' => 'other', 'name' => 'Other', 'status' => 'published', 'version' => 1]);
        $this->user->profile->forceFill(['plan_started_on' => '2026-01-01'])->save();

        $this->postJson('/api/v1/me/program', ['program_id' => $other->id], ($this->h)())->assertOk()->assertJsonPath('data.program_id', $other->id);

        expect($this->user->profile->fresh()->plan_id)->toBe($other->id)
            ->and($this->user->profile->fresh()->plan_started_on->toDateString())->toBe($this->user->localToday()->toDateString());
        $this->postJson('/api/v1/me/program', ['program_id' => 99999], ($this->h)())->assertNotFound();
    });
});

describe('announcements, banners and help', function () {
    it('shows only published announcements inside their window, pinned first', function () {
        Announcement::create(['title' => 'Draft', 'body' => 'x', 'status' => 'draft']);
        Announcement::create(['title' => 'Old', 'body' => 'x', 'status' => 'published', 'ends_at' => now()->subDay()]);
        Announcement::create(['title' => 'Future', 'body' => 'x', 'status' => 'published', 'starts_at' => now()->addDay()]);
        Announcement::create(['title' => 'Live', 'body' => 'x', 'status' => 'published', 'severity' => 'promo', 'cta_label' => 'Open', 'cta_url' => 'https://example.com']);
        Announcement::create(['title' => 'Pinned', 'body' => 'x', 'status' => 'published', 'pinned' => true]);

        $titles = collect($this->getJson('/api/v1/announcements', ($this->h)())->assertOk()->json('data'))->pluck('title')->all();

        expect($titles)->toBe(['Pinned', 'Live']);
    });

    it('targets announcements and banners by plan', function () {
        Announcement::create(['title' => 'For everyone', 'body' => 'x', 'status' => 'published']);
        Announcement::create(['title' => 'Premium only', 'body' => 'x', 'status' => 'published', 'audience' => ['premium']]);
        Banner::create(['title' => 'Premium banner', 'status' => 'published', 'audience' => ['premium']]);

        expect(collect($this->getJson('/api/v1/announcements', ($this->h)())->json('data'))->pluck('title')->all())->toBe(['For everyone']);
        expect($this->getJson('/api/v1/banners', ($this->h)())->json('data'))->toBe([]);

        $plan = MembershipPlan::factory()->create(['slug' => 'premium']);
        app(MembershipService::class)->grant($this->user, $plan, null);

        expect(collect($this->getJson('/api/v1/announcements', ($this->h)())->json('data'))->pluck('title')->sort()->values()->all())->toBe(['For everyone', 'Premium only']);
        expect(collect($this->getJson('/api/v1/banners', ($this->h)())->json('data'))->pluck('title')->all())->toBe(['Premium banner']);
    });

    it('filters banners by placement and serves published help articles', function () {
        Banner::create(['title' => 'Home one', 'status' => 'published', 'placement' => 'home']);
        Banner::create(['title' => 'Library one', 'status' => 'published', 'placement' => 'library']);
        HelpArticle::create(['slug' => 'getting-started', 'title' => 'Getting started', 'body' => 'Welcome', 'status' => 'published', 'category' => 'basics']);
        HelpArticle::create(['slug' => 'secret', 'title' => 'Secret', 'body' => 'x', 'status' => 'draft']);

        expect(collect($this->getJson('/api/v1/banners?placement=library', ($this->h)())->json('data'))->pluck('title')->all())->toBe(['Library one']);
        expect(collect($this->getJson('/api/v1/help', ($this->h)())->json('data'))->pluck('slug')->all())->toBe(['getting-started']);
        $this->getJson('/api/v1/help/getting-started', ($this->h)())->assertOk()->assertJsonPath('data.title', 'Getting started');
        $this->getJson('/api/v1/help/secret', ($this->h)())->assertNotFound();
    });
});

describe('media', function () {
    beforeEach(function () {
        Storage::fake('media');
        $this->exercise = Exercise::factory()->create(['slug' => 'clip-ex', 'name' => 'Clip Ex']);
        Storage::disk('media')->put('demos/clip.mp4', str_repeat('0123456789', 100));
        $this->media = ExerciseMedia::create(['exercise_id' => $this->exercise->id, 'kind' => 'demo_video', 'view' => 'side', 'disk' => 'media', 'path' => 'demos/clip.mp4', 'mime' => 'video/mp4', 'bytes' => 1000, 'checksum' => hash('sha256', 'clip')]);
    });

    it('lists media with signed URLs that stream the file', function () {
        $media = $this->getJson('/api/v1/exercises/clip-ex', ($this->h)())->json('data.media');

        expect($media)->toHaveCount(1)->and($media[0]['kind'])->toBe('demo_video')->and($media[0]['view'])->toBe('side')->and($media[0]['checksum'])->toBe(hash('sha256', 'clip'));

        $this->get($media[0]['url'])->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('ETag', '"'.hash('sha256', 'clip').'"');
    });

    it('supports range requests so video players can seek', function () {
        $url = MediaUrls::exercise($this->media);

        $this->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/1000');
    });

    it('rejects unsigned, tampered and expired links', function () {
        $this->get('/api/v1/media/exercise/'.$this->media->id)->assertForbidden();
        $this->get(MediaUrls::exercise($this->media).'x')->assertForbidden();

        $expired = URL::temporarySignedRoute('v1.media.exercise', now()->subMinute(), ['media' => $this->media->id]);
        $this->get($expired)->assertForbidden();
    });

    it('stops serving media once the exercise or the clip is unpublished', function () {
        $url = MediaUrls::exercise($this->media);

        $this->exercise->update(['status' => 'draft']);
        $this->get($url)->assertNotFound();

        $this->exercise->update(['status' => 'published']);
        $this->media->update(['status' => 'draft']);
        $this->get($url)->assertNotFound();
    });

    it('deletes the file from disk with its row', function () {
        $this->media->delete();

        Storage::disk('media')->assertMissing('demos/clip.mp4');
    });
});

describe('sync feed', function () {
    it('publishes a manifest with a cursor, per-collection versions and counts', function () {
        $manifest = $this->getJson('/api/v1/content/manifest', ($this->h)())->assertOk()->json('data');

        expect($manifest['cursor'])->toBe(ContentChange::latestId())->toBeGreaterThan(0)
            ->and($manifest['collections']['exercises']['count'])->toBe(Exercise::published()->count())
            ->and($manifest['collections']['programs']['count'])->toBe(1)
            ->and(array_keys($manifest['collections']))->toContain('foods', 'badges', 'help', 'announcements', 'banners', 'membership_plans');
    });

    it('pages through a full snapshot', function () {
        $total = Exercise::published()->count();
        $page1 = $this->getJson('/api/v1/content/exercises?per_page=40', ($this->h)())->assertOk();
        $page2 = $this->getJson('/api/v1/content/exercises?per_page=40&page=2', ($this->h)())->assertOk();

        expect($page1->json('meta.total'))->toBe($total)->and($page1->json('data'))->toHaveCount(40)
            ->and(collect($page1->json('data'))->pluck('id')->intersect(collect($page2->json('data'))->pluck('id'))->all())->toBe([]);
        $this->getJson('/api/v1/content/nonsense', ($this->h)())->assertNotFound();
    });

    it('reports an edit as an upsert and an unpublish as a tombstone', function () {
        $cursor = ContentChange::latestId();
        $exercise = Exercise::published()->where('kind', 'plan')->orderBy('id')->firstOrFail();

        $exercise->update(['description' => 'Updated by admin']);
        $changes = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=exercises", ($this->h)())->assertOk();
        expect($changes->json('data'))->toHaveCount(1)
            ->and($changes->json('data.0.action'))->toBe('upsert')
            ->and($changes->json('data.0.resource.description'))->toBe('Updated by admin');

        $cursor = $changes->json('meta.cursor');
        $exercise->update(['status' => 'draft']);
        $tomb = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=exercises", ($this->h)())->json('data');
        expect($tomb)->toHaveCount(1)->and($tomb[0]['action'])->toBe('delete')->and($tomb[0]['resource'])->toBeNull()->and($tomb[0]['id'])->toBe($exercise->id);
    });

    it('sends only the final state of a document edited several times', function () {
        $cursor = ContentChange::latestId();
        $exercise = Exercise::published()->firstOrFail();
        $exercise->update(['description' => 'one']);
        $exercise->update(['description' => 'two']);
        $exercise->update(['description' => 'three']);

        $data = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=exercises", ($this->h)())->json('data');

        expect($data)->toHaveCount(1)->and($data[0]['resource']['description'])->toBe('three');
    });

    it('turns a deleted document into a tombstone', function () {
        $cursor = ContentChange::latestId();
        $help = HelpArticle::create(['slug' => 'faq', 'title' => 'FAQ', 'body' => 'x', 'status' => 'published']);
        $mid = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=help", ($this->h)())->json();
        expect($mid['data'][0]['action'])->toBe('upsert');

        $help->delete();
        $data = $this->getJson('/api/v1/content/changes?after='.$mid['meta']['cursor'].'&collections=help', ($this->h)())->json('data');
        expect($data[0]['action'])->toBe('delete');
    });

    it('paginates the feed and resumes from the returned cursor without gaps or repeats', function () {
        $cursor = ContentChange::latestId();
        foreach (range(1, 5) as $i) {
            HelpArticle::create(['slug' => "a{$i}", 'title' => "A{$i}", 'body' => 'x', 'status' => 'published']);
        }

        $seen = [];
        $after = $cursor;
        do {
            $r = $this->getJson("/api/v1/content/changes?after={$after}&collections=help&limit=2", ($this->h)())->assertOk();
            foreach ($r->json('data') as $item) {
                $seen[] = $item['resource']['slug'];
            }
            $after = $r->json('meta.cursor');
        } while ($r->json('meta.has_more'));

        expect($seen)->toBe(['a1', 'a2', 'a3', 'a4', 'a5']);
        $this->getJson("/api/v1/content/changes?after={$after}&collections=help", ($this->h)())->assertJsonPath('data', []);
    });

    it('turns changes to children into an update of the parent document', function () {
        $cursor = ContentChange::latestId();
        $plan = Plan::default();
        $item = PlanDayExercise::query()->whereHas('day', fn ($q) => $q->where('plan_id', $plan->id))->firstOrFail();

        $item->update(['sets' => 7]);
        ProgramPhase::create(['plan_id' => $plan->id, 'kind' => 'phase', 'name' => 'New phase', 'starts_week' => 1, 'status' => 'published']);

        $data = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=programs", ($this->h)())->json('data');
        expect($data)->toHaveCount(1)->and($data[0]['id'])->toBe($plan->id);
        $resource = $data[0]['resource'];
        expect(collect($resource['days'])->flatMap(fn ($d) => $d['exercises'])->pluck('sets')->contains(7))->toBeTrue()
            ->and($resource['phases'][0]['name'])->toBe('New phase');
    });

    it('renaming an exercise also refreshes the programs that embed its name', function () {
        $cursor = ContentChange::latestId();
        $exercise = PlanDayExercise::firstOrFail()->exercise;

        $exercise->update(['name' => 'Renamed Lift']);

        $collections = collect($this->getJson("/api/v1/content/changes?after={$cursor}", ($this->h)())->json('data'))->pluck('collection')->unique()->sort()->values()->all();
        expect($collections)->toBe(['exercises', 'programs']);
    });

    it('records a media upload against its exercise', function () {
        Storage::fake('media');
        $exercise = Exercise::factory()->create();
        $cursor = ContentChange::latestId();

        ExerciseMedia::create(['exercise_id' => $exercise->id, 'kind' => 'image', 'disk' => 'media', 'path' => 'a.jpg', 'mime' => 'image/jpeg', 'bytes' => 1, 'checksum' => str_repeat('b', 64)]);

        $data = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=exercises", ($this->h)())->json('data');
        expect($data[0]['id'])->toBe($exercise->id)->and($data[0]['resource']['media'])->toHaveCount(1);
    });

    it('syncs shared foods but never a member\'s private foods', function () {
        seedFoods();
        $cursor = ContentChange::latestId();

        Food::create(['user_id' => $this->user->id, 'name' => 'My secret shake', 'name_search' => 'my secret shake', 'category' => 'beverages', 'kcal_100g' => 100, 'protein_100g' => 20, 'carbs_100g' => 5, 'fat_100g' => 1]);
        expect(ContentChange::latestId())->toBe($cursor);

        $shared = Food::whereNull('user_id')->first();
        $shared->update(['protein_100g' => 31.5]);
        $data = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=foods", ($this->h)())->json('data');
        expect($data)->toHaveCount(1)->and($data[0]['resource']['per_100g']['protein_g'])->toBe(31.5);
        expect(collect($this->getJson('/api/v1/content/foods?per_page=100', ($this->h)())->json('data'))->pluck('name')->all())->not->toContain('My secret shake');
    });

    it('renders each member\'s own view: a premium exercise flips from locked to unlocked on the next sync', function () {
        $exercise = premiumExercise();
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['library.premium' => 1]);
        $free->save();
        $cursor = ContentChange::latestId();
        $exercise->touch();
        $exercise->update(['description' => 'changed']);

        $locked = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=exercises", ($this->h)())->json('data.0.resource');
        expect($locked['locked'])->toBeTrue();

        $other = User::factory()->onboarded()->create();
        grantPremium($other);
        $unlocked = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=exercises", apiAs($other))->json('data.0.resource');
        expect($unlocked['locked'])->toBeFalse()->and($unlocked['description'])->toBe('changed');
    });

    it('refuses a cursor from the future or from before pruned history', function () {
        $this->getJson('/api/v1/content/changes?after='.(ContentChange::latestId() + 1000), ($this->h)())->assertStatus(410)->assertJsonPath('error.code', 'cursor_expired');

        ContentChange::query()->where('id', '<', ContentChange::latestId() - 2)->delete();
        $this->getJson('/api/v1/content/changes?after=1', ($this->h)())->assertStatus(410);
        $this->getJson('/api/v1/content/changes?after='.ContentChange::latestId(), ($this->h)())->assertOk()->assertJsonPath('data', []);
    });

    it('validates its inputs', function () {
        $this->getJson('/api/v1/content/changes', ($this->h)())->assertStatus(422);
        $this->getJson('/api/v1/content/changes?after=0&collections=exercises,bogus', ($this->h)())->assertStatus(422);
        $this->getJson('/api/v1/content/changes?after=-1', ($this->h)())->assertStatus(422);
    });
});

describe('status workflow', function () {
    it('keeps is_active and status in step both ways', function () {
        $e = Exercise::factory()->create();
        expect($e->status)->toBe('published')->and($e->is_active)->toBeTrue()->and($e->published_at)->not->toBeNull();

        $e->update(['is_active' => false]);
        expect($e->fresh()->status)->toBe('archived');

        $e->update(['status' => 'published']);
        expect($e->fresh()->is_active)->toBeTrue();

        $e->update(['status' => 'draft']);
        expect($e->fresh()->is_active)->toBeFalse();
    });
});
