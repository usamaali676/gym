<?php

use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipService;
use App\Support\Entitlement;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakePaymentVerifier;

function freePlan(): MembershipPlan
{
    return MembershipPlan::default();
}

/** Restrict the free plan so only the listed keys remain (everything else becomes a premium feature). */
function restrictFree(Entitlement ...$keep): void
{
    $plan = freePlan();
    $plan->entitlements = collect($keep)->mapWithKeys(fn (Entitlement $k) => [$k->value => true])->all();
    $plan->save();
}

function premiumPlan(array $overrides = []): MembershipPlan
{
    return MembershipPlan::factory()->create($overrides + ['name' => 'Premium', 'slug' => 'premium']);
}

function enableFakeStore(string $provider = 'apple'): FakePaymentVerifier
{
    $fake = new FakePaymentVerifier($provider);
    config(['ironpulse.payments.verifiers' => [$provider => FakePaymentVerifier::class]]);
    app()->instance(FakePaymentVerifier::class, $fake);

    return $fake;
}

describe('plans', function () {
    it('ships a default free plan that keeps every existing feature available', function () {
        $free = freePlan();

        expect($free)->not->toBeNull()->and($free->slug)->toBe('free')->and($free->status)->toBe('published');
        foreach (Entitlement::cases() as $key) {
            expect($free->grants($key))->toBeTrue("free should grant {$key->value}");
        }
    });

    it('lists only published plans, publicly, without private data', function () {
        premiumPlan(['sort_order' => 5]);
        MembershipPlan::factory()->draft()->create(['slug' => 'secret-draft']);
        MembershipPlan::factory()->create(['slug' => 'old', 'status' => 'archived']);

        $response = $this->getJson('/api/v1/membership/plans')->assertOk();

        expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['free', 'premium']);
        $response->assertJsonPath('data.1.price.currency', 'USD')
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'price' => ['amount_minor', 'currency', 'display'], 'billing' => ['interval', 'trial_days'], 'features', 'entitlements', 'limits', 'store_product_ids']]]);
    });
});

describe('server-side enforcement', function () {
    beforeEach(function () {
        seedLibrary();
        $this->user = User::factory()->onboarded()->create();
        restrictFree(Entitlement::Workouts);
    });

    it('blocks a web page the plan does not include and allows one it does', function () {
        $this->actingAs($this->user)->get(route('posture.index'))->assertForbidden()->assertSee('not in your plan');
        $this->actingAs($this->user)->get(route('plan.index'))->assertOk();
    });

    it('blocks the same-origin JSON API too, so the website cannot be used to bypass a paywall', function () {
        $this->actingAs($this->user)->postJson('/api/posture-sessions', [])->assertForbidden()->assertJsonPath('required_entitlement', 'posture_coach');
        $this->actingAs($this->user)->getJson('/api/foods/search?q=egg')->assertForbidden();
    });

    it('answers /api/v1 with the structured entitlement error', function () {
        Route::middleware(['api.v1', 'auth:sanctum', 'entitled:posture_coach'])->get('/api/v1/_probe', fn () => ['ok' => true]);

        $this->getJson('/api/v1/_probe', apiAs($this->user))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'entitlement_required')
            ->assertJsonPath('error.details.required_entitlement', 'posture_coach')
            ->assertJsonPath('error.details.current_plan', 'free');
    });

    it('lets a premium subscriber through and drops them when it expires, with no job needed', function () {
        Route::middleware(['api.v1', 'auth:sanctum', 'entitled:posture_coach'])->get('/api/v1/_probe', fn () => ['ok' => true]);
        $premium = premiumPlan();
        $sub = app(MembershipService::class)->grant($this->user, $premium, now()->addDays(10));

        $this->getJson('/api/v1/_probe', apiAs($this->user))->assertOk();

        $sub->forceFill(['ends_at' => now()->subSecond()])->save(); // status still says "active"
        $this->getJson('/api/v1/_probe', apiAs($this->user))->assertForbidden();
        expect($sub->fresh()->displayStatus())->toBe('expired');
    });

    it('takes effect on the very next request after an admin revokes', function () {
        Route::middleware(['api.v1', 'auth:sanctum', 'entitled:posture_coach'])->get('/api/v1/_probe', fn () => ['ok' => true]);
        $sub = app(MembershipService::class)->grant($this->user, premiumPlan(), null);
        $headers = apiAs($this->user);

        $this->getJson('/api/v1/_probe', $headers)->assertOk();
        app(MembershipService::class)->revoke($sub, reason: 'chargeback');
        $this->getJson('/api/v1/_probe', $headers)->assertForbidden();
    });

    it('does not let one user\'s subscription grant access to another', function () {
        Route::middleware(['api.v1', 'auth:sanctum', 'entitled:posture_coach'])->get('/api/v1/_probe', fn () => ['ok' => true]);
        $other = User::factory()->onboarded()->create();
        app(MembershipService::class)->grant($other, premiumPlan(), null);

        $this->getJson('/api/v1/_probe', apiAs($this->user))->assertForbidden();
        $this->getJson('/api/v1/_probe', apiAs($other))->assertOk();
    });
});

describe('entitlement resolution', function () {
    it('treats the free plan as a floor and unions it with the subscribed plan', function () {
        restrictFree(Entitlement::Workouts, Entitlement::Nutrition);
        $plan = premiumPlan(['entitlements' => [Entitlement::PostureCoach->value => true]]);
        $user = User::factory()->create();
        app(MembershipService::class)->grant($user, $plan, null);

        $e = app(EntitlementService::class)->for($user);

        expect($e->allows(Entitlement::Workouts))->toBeTrue()
            ->and($e->allows(Entitlement::Nutrition))->toBeTrue()
            ->and($e->allows(Entitlement::PostureCoach))->toBeTrue()
            ->and($e->allows(Entitlement::Analytics))->toBeFalse()
            ->and($e->plan->slug)->toBe('premium');
    });

    it('uses the most generous limit, where no limit means unlimited', function () {
        $free = freePlan();
        $free->limits = ['custom_foods' => 5];
        $free->save();
        $user = User::factory()->create();

        expect(app(EntitlementService::class)->for($user)->limit('custom_foods'))->toBe(5);

        app(MembershipService::class)->grant($user, premiumPlan(['limits' => ['custom_foods' => 50]]), null);
        expect(app(EntitlementService::class)->for($user)->limit('custom_foods'))->toBe(50);

        app(MembershipService::class)->grant($user, premiumPlan(['slug' => 'elite', 'limits' => []]), null);
        expect(app(EntitlementService::class)->for($user)->limit('custom_foods'))->toBeNull();
    });

    it('does not lock everyone out if a database has no plans at all', function () {
        MembershipPlan::query()->delete();

        expect(app(EntitlementService::class)->allows(User::factory()->create(), Entitlement::PostureCoach))->toBeTrue();
    });

    it('marks lapsed subscriptions as expired for reporting', function () {
        $user = User::factory()->create();
        $sub = app(MembershipService::class)->grant($user, premiumPlan(), now()->addDay());
        $sub->forceFill(['ends_at' => now()->subHour()])->save();

        expect(app(MembershipService::class)->markLapsed())->toBe(1)->and($sub->fresh()->status)->toBe('expired');
    });

    it('logs every admin change', function () {
        $user = User::factory()->create();
        $service = app(MembershipService::class);
        $sub = $service->grant($user, premiumPlan(), now()->addDay());
        $service->extend($sub, now()->addDays(30));
        $service->revoke($sub, reason: 'test');

        expect(SubscriptionEvent::where('subscription_id', $sub->id)->pluck('type')->all())
            ->toBe(['admin.granted', 'admin.extended', 'admin.revoked']);
    });
});

describe('membership endpoints', function () {
    it('describes the caller\'s plan, subscription and entitlements', function () {
        $user = User::factory()->onboarded()->create();
        app(MembershipService::class)->grant($user, premiumPlan(), now()->addDays(30));

        $this->getJson('/api/v1/membership', apiAs($user))->assertOk()
            ->assertJsonPath('data.plan.slug', 'premium')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.entitlements.posture_coach', true)
            ->assertJsonPath('data.revalidate_after_seconds', 300)
            ->assertJsonPath('data.payments.available_providers', []);
    });

    it('shows a member on the default plan with no subscription', function () {
        $user = User::factory()->onboarded()->create();

        $this->getJson('/api/v1/membership', apiAs($user))->assertOk()
            ->assertJsonPath('data.plan.slug', 'free')->assertJsonPath('data.subscription', null);
        $this->getJson('/api/v1/membership/entitlements', apiAs($user))->assertOk()->assertJsonPath('data.entitlements.nutrition', true);
    });

    it('never shows another user\'s subscription', function () {
        $mine = User::factory()->onboarded()->create();
        $other = User::factory()->onboarded()->create();
        app(MembershipService::class)->grant($other, premiumPlan(), null);

        $this->getJson('/api/v1/membership', apiAs($mine))->assertJsonPath('data.subscription', null);
    });
});

describe('store purchases', function () {
    beforeEach(function () {
        $this->user = User::factory()->onboarded()->create();
        $this->plan = premiumPlan(['store_product_ids' => ['apple' => 'com.ironpulse.premium.monthly']]);
        $this->payload = fn (array $o = []) => array_merge(['provider' => 'apple', 'product_id' => 'com.ironpulse.premium.monthly', 'receipt' => 'good:tx-1:active:30'], $o);
    });

    it('is unavailable until a store verifier is configured', function () {
        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))
            ->assertStatus(503)->assertJsonPath('error.code', 'payments_unavailable');

        enableFakeStore()->configured = false;
        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))->assertStatus(503);
    });

    it('applies a verified purchase and reports the new plan', function () {
        enableFakeStore();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))
            ->assertOk()
            ->assertJsonPath('data.plan.slug', 'premium')
            ->assertJsonPath('data.subscription.source', 'apple')
            ->assertJsonPath('data.payments.available_providers', ['apple']);

        expect(Subscription::where('user_id', $this->user->id)->count())->toBe(1);
    });

    it('is idempotent: verifying the same transaction twice keeps one subscription', function () {
        enableFakeStore();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))->assertOk();
        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))->assertOk();

        expect(Subscription::where('user_id', $this->user->id)->count())->toBe(1);
    });

    it('ignores anything the client says about price or success and trusts only the store\'s answer', function () {
        enableFakeStore();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(['receipt' => 'invalid', 'price' => 0, 'success' => true, 'status' => 'active']), apiAs($this->user))
            ->assertStatus(422)->assertJsonPath('error.code', 'purchase_invalid');

        expect(Subscription::count())->toBe(0);
    });

    it('reports a store outage as retryable, not as a rejected purchase', function () {
        enableFakeStore();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(['receipt' => 'unreachable']), apiAs($this->user))
            ->assertStatus(503)->assertJsonPath('error.code', 'payments_unreachable');
    });

    it('rejects a product that belongs to no plan', function () {
        enableFakeStore();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(['product_id' => 'com.unknown']), apiAs($this->user))
            ->assertStatus(422)->assertJsonPath('error.code', 'unknown_product');
    });

    it('rejects a receipt whose product differs from the one the client claimed', function () {
        enableFakeStore()->reportProduct = 'com.ironpulse.premium.monthly';

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(['product_id' => 'com.ironpulse.cheaper']), apiAs($this->user))
            ->assertStatus(422)->assertJsonPath('error.code', 'product_mismatch');
        expect(Subscription::count())->toBe(0);
    });

    it('does not let a second account claim an already-linked purchase', function () {
        enableFakeStore();
        $thief = User::factory()->onboarded()->create();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))->assertOk();
        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($thief))
            ->assertStatus(409)->assertJsonPath('error.code', 'purchase_already_claimed');

        expect(Subscription::where('user_id', $thief->id)->count())->toBe(0);
    });

    it('drops access when the store reports the purchase refunded', function () {
        enableFakeStore();
        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(), apiAs($this->user))->assertOk();

        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)(['receipt' => 'good:tx-1:revoked:0']), apiAs($this->user))
            ->assertOk()->assertJsonPath('data.plan.slug', 'free');
    });

    it('restores several purchases and reports each outcome', function () {
        enableFakeStore();

        $this->postJson('/api/v1/membership/restore', ['purchases' => [
            ($this->payload)(),
            ($this->payload)(['product_id' => 'com.unknown', 'receipt' => 'good:tx-9:active:30']),
        ]], apiAs($this->user))
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'rejected')
            ->assertJsonPath('data.results.1.code', 'unknown_product')
            ->assertJsonPath('data.plan.slug', 'premium');
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/membership/purchases/verify', ($this->payload)())->assertUnauthorized();
    });
});
