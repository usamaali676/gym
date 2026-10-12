<?php

use App\Models\Announcement;
use App\Models\AppRelease;
use App\Models\AppSetting;
use App\Models\AppVersionPolicy;
use App\Models\Banner;
use App\Models\ContentChange;
use App\Models\Exercise;
use App\Models\ExerciseMedia;
use App\Models\HelpArticle;
use App\Models\MembershipPlan;
use App\Models\Plan;
use App\Models\PlanDayExercise;
use App\Models\ProgramPhase;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedLibrary();
    Storage::fake('media');
    $this->admin = User::factory()->onboarded()->admin()->create();
    $this->member = User::factory()->onboarded()->create();
});

describe('access', function () {
    it('keeps every app-content screen away from members and visitors', function (string $route) {
        $url = route($route);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->member)->get($url)->assertForbidden();
    })->with([
        'admin.programs.index', 'admin.announcements.index', 'admin.announcements.create', 'admin.banners.index', 'admin.banners.create',
        'admin.help.index', 'admin.help.create', 'admin.settings.edit', 'admin.releases.index',
    ]);

    it('rejects writes from a member', function () {
        $this->actingAs($this->member);
        $this->post(route('admin.announcements.store'), ['title' => 'x', 'body' => 'y', 'severity' => 'info', 'status' => 'published'])->assertForbidden();
        $this->put(route('admin.settings.update'), [])->assertForbidden();
        $this->put(route('admin.releases.policy', 'android'), ['min_supported' => '9.0.0'])->assertForbidden();
        $this->post(route('admin.programs.store'), ['name' => 'Evil'])->assertForbidden();
        expect(Announcement::count())->toBe(0)->and(Plan::count())->toBe(1);
    });

    it('renders every page', function () {
        $plan = Plan::default();
        $a = Announcement::create(['title' => 'A', 'body' => 'b', 'status' => 'draft']);
        $b = Banner::create(['title' => 'B', 'status' => 'draft']);
        $h = HelpArticle::create(['slug' => 'h', 'title' => 'H', 'body' => 'b', 'status' => 'draft']);
        $rel = AppRelease::create(['platform' => 'all', 'version' => '1.0.0', 'notes' => 'n']);
        $this->actingAs($this->admin);

        foreach ([
            route('admin.programs.index'), route('admin.programs.edit', $plan), route('admin.plan.index', ['plan' => $plan->id]),
            route('admin.announcements.index'), route('admin.announcements.create'), route('admin.announcements.edit', $a),
            route('admin.banners.index'), route('admin.banners.create'), route('admin.banners.edit', $b),
            route('admin.help.index'), route('admin.help.create'), route('admin.help.edit', $h),
            route('admin.settings.edit'), route('admin.releases.index'), route('admin.exercises.edit', Exercise::first()),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        expect($rel->exists)->toBeTrue();
    });
});

describe('programs', function () {
    it('creates a draft copy of a program including its days and exercises, invisible to apps until published', function () {
        $source = Plan::default();
        $this->actingAs($this->admin)->post(route('admin.programs.store'), ['name' => 'Summer Cut', 'copy_from' => $source->id])->assertRedirect();

        $copy = Plan::where('slug', 'summer-cut')->firstOrFail();
        expect($copy->status)->toBe('draft')->and($copy->is_default)->toBeFalse()
            ->and($copy->days()->count())->toBe($source->days()->count())
            ->and(PlanDayExercise::whereIn('plan_day_id', $copy->days()->pluck('id'))->count())->toBe(PlanDayExercise::whereIn('plan_day_id', $source->days()->pluck('id'))->count());

        $ids = fn () => collect($this->getJson('/api/v1/programs', apiAs($this->member))->json('data'))->pluck('id')->all();
        expect($ids())->not->toContain($copy->id);

        $this->actingAs($this->admin)->patch(route('admin.programs.update', $copy), ['name' => 'Summer Cut', 'status' => 'published'])->assertSessionHasNoErrors();
        expect($ids())->toContain($copy->id);
    });

    it('does not touch the original when the copy is edited', function () {
        $source = Plan::default();
        $before = PlanDayExercise::query()->whereIn('plan_day_id', $source->days()->pluck('id'))->pluck('sets', 'id')->all();
        $this->actingAs($this->admin)->post(route('admin.programs.store'), ['name' => 'Copy', 'copy_from' => $source->id]);
        $copyItem = PlanDayExercise::query()->whereIn('plan_day_id', Plan::where('slug', 'copy')->first()->days()->pluck('id'))->firstOrFail();

        $copyItem->update(['sets' => 9]);

        expect(PlanDayExercise::query()->whereIn('plan_day_id', $source->days()->pluck('id'))->pluck('sets', 'id')->all())->toBe($before);
    });

    it('keeps one default program, published and free', function () {
        $other = Plan::create(['slug' => 'other', 'name' => 'Other', 'status' => 'published', 'version' => 1]);
        $this->actingAs($this->admin);

        $this->patch(route('admin.programs.update', Plan::default()), ['name' => 'X', 'status' => 'draft'])->assertSessionHasErrors('status');
        $this->patch(route('admin.programs.update', Plan::default()), ['name' => 'X', 'status' => 'published', 'is_default' => 0])->assertSessionHasErrors('is_default');
        $this->patch(route('admin.programs.update', $other), ['name' => 'Other', 'status' => 'published', 'is_default' => 1, 'required_entitlement' => 'programs.premium'])->assertSessionHasErrors('required_entitlement');

        $this->patch(route('admin.programs.update', $other), ['name' => 'Other', 'status' => 'published', 'is_default' => 1])->assertSessionHasNoErrors();
        expect(Plan::default()->id)->toBe($other->id)->and(Plan::where('is_default', true)->count())->toBe(1);
    });

    it('gates a program behind the premium entitlement from the form', function () {
        $plan = Plan::create(['slug' => 'elite', 'name' => 'Elite', 'status' => 'draft', 'version' => 1]);
        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['programs.premium' => 1]);
        $free->save();

        $this->actingAs($this->admin)->patch(route('admin.programs.update', $plan), ['name' => 'Elite', 'status' => 'published', 'required_entitlement' => 'programs.premium']);

        $this->getJson("/api/v1/programs/{$plan->id}", apiAs($this->member))->assertForbidden()->assertJsonPath('error.code', 'entitlement_required');
    });

    it('manages sprints and phases that then appear in the API', function () {
        $plan = Plan::default();
        $this->actingAs($this->admin);

        $this->post(route('admin.programs.phases.store', $plan), ['kind' => 'sprint', 'name' => 'Foundation', 'starts_week' => 1, 'ends_week' => 4, 'status' => 'published', 'summary' => 'Learn the lifts'])->assertSessionHasNoErrors();
        $phase = ProgramPhase::firstOrFail();
        expect($this->getJson("/api/v1/programs/{$plan->id}", apiAs($this->member))->json('data.phases.0.name'))->toBe('Foundation');

        $this->patch(route('admin.programs.phases.update', $phase), ['kind' => 'phase', 'name' => 'Build', 'starts_week' => 5, 'status' => 'published'])->assertSessionHasNoErrors();
        expect($this->getJson("/api/v1/programs/{$plan->id}", apiAs($this->member))->json('data.phases.0'))->toMatchArray(['name' => 'Build', 'starts_week' => 5, 'ends_week' => null]);

        $this->post(route('admin.programs.phases.store', $plan), ['kind' => 'phase', 'name' => 'Bad', 'starts_week' => 6, 'ends_week' => 2, 'status' => 'draft'])->assertSessionHasErrors('ends_week');

        $this->delete(route('admin.programs.phases.destroy', $phase));
        expect($this->getJson("/api/v1/programs/{$plan->id}", apiAs($this->member))->json('data.phases'))->toBe([]);
    });

    it('lets a member keep following a program after it is archived, but stops offering it', function () {
        $program = Plan::create(['slug' => 'old', 'name' => 'Old', 'status' => 'published', 'version' => 1]);
        $this->member->profile->forceFill(['plan_id' => $program->id])->save();
        $program->update(['status' => 'archived']);

        $offered = collect($this->getJson('/api/v1/programs', apiAs($this->member))->json('data'))->pluck('id')->all();
        expect($offered)->not->toContain($program->id);
        $this->getJson("/api/v1/programs/{$program->id}", apiAs($this->member))->assertOk();

        $stranger = User::factory()->onboarded()->create();
        $this->getJson("/api/v1/programs/{$program->id}", apiAs($stranger))->assertNotFound();
    });
});

describe('announcements', function () {
    it('publishes an announcement to the apps and validates links', function () {
        $this->actingAs($this->admin);
        $payload = ['title' => 'New plan!', 'body' => 'Check it out', 'severity' => 'promo', 'status' => 'published', 'cta_label' => 'Open', 'cta_url' => 'ironpulse://programs'];

        $this->post(route('admin.announcements.store'), $payload)->assertSessionHasNoErrors();
        expect(collect($this->getJson('/api/v1/announcements', apiAs($this->member))->json('data'))->pluck('title')->all())->toBe(['New plan!']);

        foreach (['javascript:alert(1)', 'http://insecure.example', 'ftp://x', 'data:text/html,hi'] as $bad) {
            $this->post(route('admin.announcements.store'), ['cta_url' => $bad] + $payload)->assertSessionHasErrors('cta_url');
        }
        $this->post(route('admin.announcements.store'), ['cta_url' => 'https://ok.example/a'] + $payload)->assertSessionHasNoErrors();
        $this->post(route('admin.announcements.store'), ['cta_label' => null] + $payload)->assertSessionHasErrors('cta_label');
    });

    it('checks the window, the audience and the status', function () {
        $this->actingAs($this->admin);
        $base = ['title' => 'T', 'body' => 'B', 'severity' => 'info', 'status' => 'published'];

        $this->post(route('admin.announcements.store'), $base + ['starts_at' => '2026-12-02T10:00', 'ends_at' => '2026-12-01T10:00'])->assertSessionHasErrors('ends_at');
        $this->post(route('admin.announcements.store'), $base + ['audience' => ['no-such-plan']])->assertSessionHasErrors('audience.0');
        $this->post(route('admin.announcements.store'), ['severity' => 'scream'] + $base)->assertSessionHasErrors('severity');
        $this->post(route('admin.announcements.store'), $base + ['audience' => ['free'], 'pinned' => 1])->assertSessionHasNoErrors();
        expect(Announcement::first()->audience)->toBe(['free'])->and(Announcement::first()->pinned)->toBeTrue();
    });

    it('updates, hides and deletes', function () {
        $a = Announcement::create(['title' => 'Old', 'body' => 'b', 'status' => 'published']);
        $this->actingAs($this->admin);

        $this->patch(route('admin.announcements.update', $a), ['title' => 'New', 'body' => 'b', 'severity' => 'info', 'status' => 'archived']);
        expect($a->fresh()->title)->toBe('New')->and($this->getJson('/api/v1/announcements', apiAs($this->member))->json('data'))->toBe([]);

        $this->delete(route('admin.announcements.destroy', $a));
        expect(Announcement::count())->toBe(0);
    });
});

describe('banners', function () {
    it('stores an uploaded image under a random name and serves it through a signed link', function () {
        $this->actingAs($this->admin)->post(route('admin.banners.store'), [
            'title' => 'Summer', 'placement' => 'home', 'status' => 'published', 'image' => realImage('../../evil name.png'),
        ])->assertSessionHasNoErrors();

        $banner = Banner::firstOrFail();
        expect($banner->image_path)->toMatch('#^banners/[A-Za-z0-9]{40}\.png$#')
            ->and($banner->image_mime)->toBe('image/png')->and($banner->image_checksum)->toHaveLength(64);
        Storage::disk('media')->assertExists($banner->image_path);

        $image = $this->getJson('/api/v1/banners', apiAs($this->member))->json('data.0.image');
        $this->get($image['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    });

    it('rejects files that are not safe images, whatever their name says', function () {
        $this->actingAs($this->admin);
        $base = ['title' => 'X', 'placement' => 'home', 'status' => 'draft'];
        $svg = realUpload('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $php = realUpload('shell.jpg', '<?php system($_GET["c"]); ?>');
        $pdf = realUpload('doc.png', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");

        foreach ([$svg, $php, $pdf] as $file) {
            $this->post(route('admin.banners.store'), $base + ['image' => $file])->assertSessionHasErrors('image');
        }
        expect(Banner::count())->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([]);
    });

    it('replaces and removes the image, deleting the old file', function () {
        $this->actingAs($this->admin)->post(route('admin.banners.store'), ['title' => 'B', 'placement' => 'home', 'status' => 'draft', 'image' => realImage('a.jpg', 'jpeg')]);
        $banner = Banner::firstOrFail();
        $first = $banner->image_path;

        $this->patch(route('admin.banners.update', $banner), ['title' => 'B', 'placement' => 'home', 'status' => 'draft', 'image' => realImage('b.png')]);
        Storage::disk('media')->assertMissing($first);
        $second = $banner->fresh()->image_path;
        Storage::disk('media')->assertExists($second);

        $this->patch(route('admin.banners.update', $banner), ['title' => 'B', 'placement' => 'home', 'status' => 'draft', 'remove_image' => 1]);
        Storage::disk('media')->assertMissing($second);
        expect($banner->fresh()->image_path)->toBeNull();
    });
});

describe('help articles and settings', function () {
    it('creates help articles with unique addresses', function () {
        $this->actingAs($this->admin);
        $payload = ['title' => 'Getting started', 'category' => 'basics', 'body' => 'Hello **there**', 'status' => 'published'];

        $this->post(route('admin.help.store'), $payload)->assertSessionHasNoErrors();
        expect(HelpArticle::first()->slug)->toBe('getting-started');
        $this->post(route('admin.help.store'), $payload)->assertSessionHasErrors('slug');
        $this->getJson('/api/v1/help/getting-started', apiAs($this->member))->assertOk()->assertJsonPath('data.body', 'Hello **there**');

        $this->patch(route('admin.help.update', HelpArticle::first()), ['status' => 'draft'] + $payload);
        $this->getJson('/api/v1/help/getting-started', apiAs($this->member))->assertNotFound();
    });

    it('saves the registered settings and shows them in the app config', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['settings' => [
            'support_email' => 'help@gym.example', 'support_url' => 'https://gym.example/help', 'legal_terms_url' => 'https://gym.example/terms',
            'home_tagline' => 'Train hard', 'features_leaderboard' => '0', 'features_help_center' => '1',
        ]])->assertSessionHasNoErrors();

        $settings = $this->getJson('/api/v1/app/config')->json('data.settings');
        expect($settings)->toMatchArray(['support.email' => 'help@gym.example', 'support.url' => 'https://gym.example/help', 'home.tagline' => 'Train hard', 'features.leaderboard' => false, 'features.help_center' => true]);
        expect(AppSetting::count())->toBe(7);
    });

    it('only accepts https links in settings', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['settings' => ['legal_privacy_url' => 'http://insecure.example']])->assertSessionHasErrors('settings.legal_privacy_url');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['settings' => ['legal_privacy_url' => 'javascript:alert(1)']])->assertSessionHasErrors('settings.legal_privacy_url');
    });
});

describe('app releases', function () {
    it('sets the version policy and the apps obey it', function () {
        $this->actingAs($this->admin)->put(route('admin.releases.policy', 'android'), [
            'min_supported' => '2.0.0', 'recommended' => '2.1.0', 'latest' => '2.2.0', 'store_url' => 'https://play.google.com/store/apps/details?id=x', 'release_notes' => 'Big update',
        ])->assertSessionHasNoErrors();

        $this->getJson('/api/v1/membership/plans', ['X-App-Platform' => 'android', 'X-App-Version' => '1.9.9'])->assertStatus(426)->assertJsonPath('error.details.latest', '2.2.0');
        $this->getJson('/api/v1/membership/plans', ['X-App-Platform' => 'android', 'X-App-Version' => '2.0.0'])->assertOk();
    });

    it('validates versions and their order', function () {
        $this->actingAs($this->admin);

        $this->put(route('admin.releases.policy', 'ios'), ['min_supported' => 'soon'])->assertSessionHasErrors('min_supported');
        $this->put(route('admin.releases.policy', 'ios'), ['min_supported' => '2.0.0', 'recommended' => '1.0.0'])->assertSessionHasErrors('recommended');
        $this->put(route('admin.releases.policy', 'ios'), ['min_supported' => '2.0.0', 'store_url' => 'http://x.example'])->assertSessionHasErrors('store_url');
        $this->put(route('admin.releases.policy', 'windows'), ['min_supported' => '1.0.0'])->assertNotFound();
        expect(AppVersionPolicy::forPlatform('ios')->min_supported)->toBe('1.0.0');
    });

    it('switches maintenance mode on and off', function () {
        $this->actingAs($this->admin)->put(route('admin.releases.policy', 'android'), ['min_supported' => '1.0.0', 'maintenance_enabled' => 1, 'maintenance_message' => 'Back soon']);
        $h = ['X-App-Platform' => 'android', 'X-App-Version' => '1.0.0'];
        $this->getJson('/api/v1/membership/plans', $h)->assertStatus(503)->assertJsonPath('error.message', 'Back soon');

        $this->actingAs($this->admin)->put(route('admin.releases.policy', 'android'), ['min_supported' => '1.0.0', 'maintenance_enabled' => 0]);
        $this->getJson('/api/v1/membership/plans', $h)->assertOk();
    });

    it('publishes, hides and deletes release notes', function () {
        $this->actingAs($this->admin)->post(route('admin.releases.store'), ['platform' => 'all', 'version' => '1.2.0', 'notes' => 'Faster', 'is_published' => 1])->assertSessionHasNoErrors();
        $release = AppRelease::firstOrFail();
        expect($this->getJson('/api/v1/app/config')->json('data.releases.0.notes'))->toBe('Faster');

        $this->patch(route('admin.releases.toggle', $release));
        expect($this->getJson('/api/v1/app/config')->json('data.releases'))->toBe([]);
        $this->patch(route('admin.releases.toggle', $release));
        expect($this->getJson('/api/v1/app/config')->json('data.releases'))->toHaveCount(1);

        $this->post(route('admin.releases.store'), ['platform' => 'all', 'version' => 'next', 'notes' => 'x'])->assertSessionHasErrors('version');
        $this->delete(route('admin.releases.destroy', $release));
        expect(AppRelease::count())->toBe(0);
    });
});

describe('exercise publishing and media', function () {
    it('uploads a demo clip that appears in the API and replaces on re-upload', function () {
        $exercise = Exercise::where('kind', 'plan')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.exercises.media.store', $exercise), ['kind' => 'demo_video', 'view' => 'side', 'file' => fakeMp4()])->assertSessionHasNoErrors();

        $media = ExerciseMedia::firstOrFail();
        expect($media->mime)->toBe('video/mp4')->and($media->path)->toMatch('#^exercises/'.preg_quote($exercise->slug).'/demo_video-side-[a-z0-9]{12}\.mp4$#');
        $clip = $this->getJson("/api/v1/exercises/{$exercise->slug}", apiAs($this->member))->json('data.media.0');
        expect($clip)->toMatchArray(['kind' => 'demo_video', 'view' => 'side', 'mime' => 'video/mp4']);
        $this->get($clip['url'])->assertOk();

        $oldPath = $media->path;
        $this->actingAs($this->admin)->post(route('admin.exercises.media.store', $exercise), ['kind' => 'demo_video', 'view' => 'side', 'file' => fakeMp4('again.mp4')]);
        expect(ExerciseMedia::count())->toBe(1);
        Storage::disk('media')->assertMissing($oldPath);
    });

    it('keeps video and image kinds apart and rejects other files', function () {
        $exercise = Exercise::first();
        $this->actingAs($this->admin);

        $this->post(route('admin.exercises.media.store', $exercise), ['kind' => 'image', 'file' => fakeMp4()])->assertSessionHasErrors('file');
        $this->post(route('admin.exercises.media.store', $exercise), ['kind' => 'demo_video', 'file' => realImage('a.jpg', 'jpeg')])->assertSessionHasErrors('file');
        $this->post(route('admin.exercises.media.store', $exercise), ['kind' => 'image', 'file' => realUpload('x.jpg', '<?php echo 1;')])->assertSessionHasErrors('file');
        $this->post(route('admin.exercises.media.store', $exercise), ['kind' => 'image', 'view' => 'top', 'file' => realImage('a.jpg', 'jpeg')])->assertSessionHasErrors('view');
        expect(ExerciseMedia::count())->toBe(0);

        $this->post(route('admin.exercises.media.store', $exercise), ['kind' => 'thumbnail', 'file' => realImage('t.webp', 'webp')])->assertSessionHasNoErrors();
        expect(ExerciseMedia::first()->width)->not->toBeNull();
    });

    it('hides and removes media', function () {
        $exercise = Exercise::where('kind', 'plan')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.exercises.media.store', $exercise), ['kind' => 'image', 'file' => realImage('a.jpg', 'jpeg')]);
        $media = ExerciseMedia::firstOrFail();

        $this->patch(route('admin.exercise-media.update', $media), ['status' => 'draft']);
        expect($this->getJson("/api/v1/exercises/{$exercise->slug}", apiAs($this->member))->json('data.media'))->toBe([]);

        $this->delete(route('admin.exercise-media.destroy', $media));
        Storage::disk('media')->assertMissing($media->path);
    });

    it('publishes, drafts and premium-gates an exercise from its form', function () {
        $exercise = Exercise::where('kind', 'alternative')->firstOrFail();
        $form = fn (array $o = []) => array_merge([
            'name' => $exercise->name, 'slug' => $exercise->slug, 'kind' => $exercise->kind->value, 'muscle_group' => $exercise->muscle_group->value,
            'mechanic' => $exercise->mechanic->value, 'difficulty' => $exercise->difficulty->value, 'rest_min_seconds' => 60, 'rest_max_seconds' => 90, 'default_rest_seconds' => 75,
        ], $o);
        $this->actingAs($this->admin);

        $this->patch(route('admin.exercises.update', $exercise), $form(['status' => 'draft']))->assertSessionHasNoErrors();
        expect($exercise->fresh())->status->toBe('draft')->is_active->toBeFalse();
        $this->getJson("/api/v1/exercises/{$exercise->slug}", apiAs($this->member))->assertNotFound();

        $free = MembershipPlan::default();
        $free->entitlements = array_diff_key($free->entitlements, ['library.premium' => 1]);
        $free->save();
        $this->patch(route('admin.exercises.update', $exercise), $form(['status' => 'published', 'required_entitlement' => 'library.premium']));
        $this->getJson("/api/v1/exercises/{$exercise->slug}", apiAs($this->member))->assertOk()->assertJsonPath('data.locked', true);

        $this->patch(route('admin.exercises.update', $exercise), $form(['status' => 'published', 'required_entitlement' => 'bogus']))->assertSessionHasErrors('required_entitlement');
    });

    it('still understands the older visible checkbox', function () {
        $exercise = Exercise::where('kind', 'alternative')->firstOrFail();
        $form = ['name' => $exercise->name, 'slug' => $exercise->slug, 'kind' => $exercise->kind->value, 'muscle_group' => $exercise->muscle_group->value, 'mechanic' => $exercise->mechanic->value, 'difficulty' => $exercise->difficulty->value, 'rest_min_seconds' => 60, 'rest_max_seconds' => 90, 'default_rest_seconds' => 75];
        $this->actingAs($this->admin);

        $this->patch(route('admin.exercises.update', $exercise), $form + ['is_active' => 0]);
        expect($exercise->fresh()->status)->toBe('archived');
        $this->patch(route('admin.exercises.update', $exercise), $form + ['is_active' => 1]);
        expect($exercise->fresh()->status)->toBe('published');
    });
});

describe('membership plans reach the apps through the content feed', function () {
    it('records plan edits as a membership_plans change', function () {
        $cursor = ContentChange::latestId();
        $this->actingAs($this->admin)->post(route('admin.memberships.store'), [
            'name' => 'Pro', 'status' => 'published', 'price' => '4.99', 'currency' => 'usd', 'billing_interval' => 'month', 'interval_count' => 1, 'trial_days' => 0, 'grace_days' => 0, 'entitlements' => ['nutrition' => 1],
        ])->assertSessionHasNoErrors();

        $changes = $this->getJson("/api/v1/content/changes?after={$cursor}&collections=membership_plans", apiAs($this->member))->json('data');
        expect($changes)->toHaveCount(1)->and($changes[0]['resource']['name'])->toBe('Pro');
    });
});
