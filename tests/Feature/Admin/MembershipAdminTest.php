<?php

use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Membership\MembershipService;
use App\Support\Entitlement;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->onboarded()->admin()->create();
});

function planForm(array $o = []): array
{
    return array_merge([
        'name' => 'Premium',
        'slug' => '',
        'description' => 'All the things',
        'status' => 'published',
        'price' => '9.99',
        'currency' => 'usd',
        'billing_interval' => 'month',
        'interval_count' => 1,
        'trial_days' => 7,
        'grace_days' => 3,
        'auto_renews' => 1,
        'features' => "Posture coach\n\nAnalytics\n",
        'entitlements' => ['posture_coach' => 1, 'analytics' => 1, 'nutrition' => 0],
        'limits' => ['custom_foods' => '', 'progress_photos' => '200'],
        'store_product_ids' => ['apple' => 'com.ironpulse.premium', 'google' => ''],
        'sort_order' => 10,
    ], $o);
}

describe('access', function () {
    it('keeps membership admin away from members and visitors', function () {
        $member = User::factory()->onboarded()->create();

        $routes = ['admin.memberships.index', 'admin.memberships.create', 'admin.subscriptions.index'];
        foreach ($routes as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        foreach ($routes as $route) {
            $this->actingAs($member)->get(route($route))->assertForbidden();
        }
        $this->actingAs($member)->post(route('admin.memberships.store'), planForm())->assertForbidden();
        $this->actingAs($member)->post(route('admin.subscriptions.grant', $member), ['plan_id' => 1, 'source' => 'admin'])->assertForbidden();
        expect(MembershipPlan::count())->toBe(1);
    });

    it('renders every membership page', function () {
        $plan = MembershipPlan::factory()->create();
        $user = User::factory()->onboarded()->create();
        app(MembershipService::class)->grant($user, $plan, now()->addDays(5));

        $this->actingAs($this->admin);
        $this->get(route('admin.memberships.index'))->assertOk()->assertSee($plan->name)->assertSee('Default');
        $this->get(route('admin.memberships.create'))->assertOk()->assertSee('Camera posture coach');
        $this->get(route('admin.memberships.edit', $plan))->assertOk();
        $this->get(route('admin.memberships.edit', MembershipPlan::default()))->assertOk();
        $this->get(route('admin.subscriptions.index'))->assertOk()->assertSee($user->name);
        $this->get(route('admin.subscriptions.index', ['status' => 'effective', 'q' => 'zzz']))->assertOk()->assertSee('No subscriptions match');
        $this->get(route('admin.users.show', $user))->assertOk()->assertSee('Membership')->assertSee('Revoke');
    });
});

describe('plans', function () {
    it('creates a plan from the form, converting price and shaping the lists', function () {
        $this->actingAs($this->admin)->post(route('admin.memberships.store'), planForm())->assertRedirect();

        $plan = MembershipPlan::where('slug', 'premium')->firstOrFail();
        expect($plan->price_minor)->toBe(999)
            ->and($plan->currency)->toBe('USD')
            ->and($plan->features)->toBe(['Posture coach', 'Analytics'])
            ->and($plan->grants('posture_coach'))->toBeTrue()
            ->and($plan->grants('nutrition'))->toBeFalse()
            ->and($plan->limit('progress_photos'))->toBe(200)
            ->and($plan->limit('custom_foods'))->toBeNull()
            ->and($plan->store_product_ids)->toBe(['apple' => 'com.ironpulse.premium'])
            ->and($plan->trial_days)->toBe(7)
            ->and($plan->published_at)->not->toBeNull();
        // every known entitlement is stored explicitly, so a key added later is "off" rather than "missing"
        expect(array_keys($plan->entitlements))->toEqualCanonicalizing(Entitlement::keys());
    });

    it('shows a published plan to the apps and hides a draft', function () {
        $this->actingAs($this->admin)->post(route('admin.memberships.store'), planForm(['status' => 'draft']));
        expect(collect($this->getJson('/api/v1/membership/plans')->json('data'))->pluck('slug')->all())->toBe(['free']);

        $plan = MembershipPlan::where('slug', 'premium')->first();
        $this->actingAs($this->admin)->patch(route('admin.memberships.update', $plan), planForm(['status' => 'published']));
        expect(collect($this->getJson('/api/v1/membership/plans')->json('data'))->pluck('slug')->all())->toContain('premium');
    });

    it('validates input', function () {
        $this->actingAs($this->admin)->post(route('admin.memberships.store'), planForm(['price' => '-1', 'billing_interval' => 'fortnightly', 'trial_days' => 999, 'currency' => 'EURO']))
            ->assertSessionHasErrors(['price', 'billing_interval', 'trial_days', 'currency']);
        $this->actingAs($this->admin)->post(route('admin.memberships.store'), planForm(['slug' => 'free']))->assertSessionHasErrors('slug');
    });

    it('changes who can reach a feature: restricting the free plan locks the website too', function () {
        seedLibrary();
        $member = User::factory()->onboarded()->create();
        $free = MembershipPlan::default();

        $this->actingAs($member)->get(route('posture.index'))->assertOk();

        $this->actingAs($this->admin)->patch(route('admin.memberships.update', $free), planForm([
            'name' => 'Free', 'slug' => 'free', 'price' => '0', 'billing_interval' => 'none', 'trial_days' => 0, 'grace_days' => 0,
            'is_default' => 1, 'entitlements' => ['workouts' => 1, 'nutrition' => 1, 'posture_coach' => 0], 'features' => 'Plan',
        ]))->assertSessionHasNoErrors();

        auth()->logout();
        $this->actingAs($member)->get(route('posture.index'))->assertForbidden();
        $this->actingAs($member)->get(route('plan.index'))->assertOk();
    });

    it('keeps exactly one default plan, which must be free and published', function () {
        $premium = MembershipPlan::factory()->create(['slug' => 'premium', 'price_minor' => 999]);
        $this->actingAs($this->admin);

        $this->patch(route('admin.memberships.update', $premium), planForm(['slug' => 'premium', 'is_default' => 1]))->assertSessionHasErrors('price');

        $this->patch(route('admin.memberships.update', $premium), planForm(['slug' => 'premium', 'price' => '0', 'is_default' => 1]))->assertSessionHasNoErrors();
        expect(MembershipPlan::where('is_default', true)->pluck('slug')->all())->toBe(['premium']);

        $this->patch(route('admin.memberships.update', $premium), planForm(['slug' => 'premium', 'price' => '0', 'is_default' => 0]))->assertSessionHasErrors('is_default');
        $this->patch(route('admin.memberships.update', $premium), planForm(['slug' => 'premium', 'price' => '0', 'is_default' => 1, 'status' => 'draft']))->assertSessionHasErrors('status');
    });

    it('archives instead of deleting, and never archives the default', function () {
        $plan = MembershipPlan::factory()->create();
        $this->actingAs($this->admin);

        $this->delete(route('admin.memberships.destroy', MembershipPlan::default()))->assertSessionHasErrors('plan');
        $this->delete(route('admin.memberships.destroy', $plan))->assertRedirect(route('admin.memberships.index'));

        expect($plan->fresh()->status)->toBe('archived')->and(MembershipPlan::count())->toBe(2);
    });
});

describe('subscriptions', function () {
    it('grants, extends and revokes from the member page', function () {
        $plan = MembershipPlan::factory()->create();
        $member = User::factory()->onboarded()->create();
        $this->actingAs($this->admin);

        $this->post(route('admin.subscriptions.grant', $member), ['plan_id' => $plan->id, 'source' => 'promo', 'ends_on' => now()->addDays(10)->toDateString(), 'notes' => 'beta tester'])->assertSessionHasNoErrors();
        $sub = Subscription::firstOrFail();
        expect($sub->source)->toBe('promo')->and($sub->granted_by)->toBe($this->admin->id)->and($sub->isEffective())->toBeTrue();

        $this->patch(route('admin.subscriptions.extend', $sub), ['ends_on' => now()->addDays(40)->toDateString()]);
        expect($sub->fresh()->ends_at->isAfter(now()->addDays(39)))->toBeTrue();

        $this->delete(route('admin.subscriptions.revoke', $sub), ['reason' => 'abuse']);
        expect($sub->fresh()->isEffective())->toBeFalse()->and($sub->fresh()->status)->toBe('revoked');
    });

    it('rejects a past end date', function () {
        $plan = MembershipPlan::factory()->create();
        $member = User::factory()->onboarded()->create();

        $this->actingAs($this->admin)->post(route('admin.subscriptions.grant', $member), ['plan_id' => $plan->id, 'source' => 'admin', 'ends_on' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('ends_on');
    });

    it('lets the mobile app see the change on its next call', function () {
        $plan = MembershipPlan::factory()->create(['slug' => 'premium']);
        $member = User::factory()->onboarded()->create();
        $headers = apiAs($member);

        $this->getJson('/api/v1/membership', $headers)->assertJsonPath('data.plan.slug', 'free');
        $this->actingAs($this->admin)->post(route('admin.subscriptions.grant', $member), ['plan_id' => $plan->id, 'source' => 'admin']);
        auth('web')->logout();
        $this->getJson('/api/v1/membership', $headers)->assertJsonPath('data.plan.slug', 'premium');
    });
});

describe('suspension', function () {
    it('signs a suspended member out of the website and the apps, and blocks sign-in', function () {
        $member = User::factory()->onboarded()->create(['email' => 'sus@example.com']);
        $token = apiToken($member);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->patch(route('admin.users.status', $member), ['status' => 'suspended', 'reason' => 'fraud'])->assertSessionHasNoErrors();

        expect($member->fresh()->isSuspended())->toBeTrue()
            ->and($member->tokens()->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
        $this->getJson('/api/v1/me', bearer($token))->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'sus@example.com', 'password' => 'password', 'device' => deviceBlock()])->assertForbidden();

        auth('web')->logout();
        $this->post('/login', ['email' => 'sus@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // an already-open web session is cut off on its next request
        $this->actingAs($member->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    });

    it('restores access when un-suspended', function () {
        $member = User::factory()->onboarded()->create(['status' => 'suspended']);

        $this->actingAs($this->admin)->patch(route('admin.users.status', $member), ['status' => 'active']);
        auth('web')->logout();

        expect($member->fresh()->isSuspended())->toBeFalse();
        $this->getJson('/api/v1/me', apiAs($member))->assertOk();
    });

    it('will not let an admin suspend themselves or the last admin', function () {
        $this->actingAs($this->admin)->patch(route('admin.users.status', $this->admin), ['status' => 'suspended'])->assertSessionHasErrors('status');

        $other = User::factory()->onboarded()->admin()->create();
        $this->actingAs($this->admin)->patch(route('admin.users.status', $other), ['status' => 'suspended'])->assertSessionHasNoErrors();
        expect($other->fresh()->isSuspended())->toBeTrue();
    });
});
