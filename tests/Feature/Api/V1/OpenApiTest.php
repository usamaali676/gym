<?php

use App\Models\Food;
use App\Models\PostureRule;
use App\Models\User;
use App\Support\Entitlement;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApi;

/** @return array<string, LaravelRoute> keyed "METHOD /path" */
function v1Routes(): array
{
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') || ! str_starts_with((string) $route->getName(), 'v1.')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[strtoupper($method).' /'.substr($route->uri(), strlen('api/v1/'))] = $route;
        }
    }
    ksort($routes);

    return $routes;
}

/** @return array<string, array<string, mixed>> keyed "METHOD /path" */
function specOperations(): array
{
    $ops = [];
    foreach (OpenApi::spec()['paths'] as $path => $methods) {
        foreach ($methods as $method => $op) {
            $ops[strtoupper($method).' '.$path] = $op;
        }
    }
    ksort($ops);

    return $ops;
}

describe('route parity', function () {
    it('documents every route and documents nothing that does not exist', function () {
        $routes = array_keys(v1Routes());
        $documented = array_keys(specOperations());

        expect(array_values(array_diff($routes, $documented)))->toBe([], 'routes missing from docs/mobile/openapi.yaml')
            ->and(array_values(array_diff($documented, $routes)))->toBe([], 'documented operations without a route');
    });

    it('marks exactly the routes without token authentication as public', function () {
        foreach (v1Routes() as $key => $route) {
            $requiresAuth = collect(app('router')->gatherRouteMiddleware($route))->contains(fn ($m) => is_string($m) && str_ends_with($m, 'Authenticate:sanctum'));
            $op = specOperations()[$key];
            $documentedPublic = ($op['security'] ?? null) === [];

            expect($documentedPublic)->toBe(! $requiresAuth, "{$key} public/private mismatch");
        }
    });

    it('declares the entitlement each route enforces', function () {
        foreach (v1Routes() as $key => $route) {
            $enforced = collect(app('router')->gatherRouteMiddleware($route))->first(fn ($m) => is_string($m) && str_contains($m, 'EnsureEntitled:'));
            $enforced = $enforced ? explode(':', $enforced, 2)[1] : null;

            expect(specOperations()[$key]['x-entitlement'] ?? null)->toBe($enforced, "{$key} entitlement mismatch");
        }
    });

    it('marks routes that need a finished profile', function () {
        foreach (v1Routes() as $key => $route) {
            $needs = collect(app('router')->gatherRouteMiddleware($route))->contains(fn ($m) => is_string($m) && str_ends_with($m, 'EnsureOnboardedApi'));

            expect(specOperations()[$key]['x-requires-onboarding'] ?? false)->toBe($needs, "{$key} onboarding mismatch");
        }
    });

    it('documents path parameters exactly as the route declares them', function () {
        foreach (v1Routes() as $key => $route) {
            $declared = collect($route->parameterNames())->sort()->values()->all();
            $documented = collect(specOperations()[$key]['parameters'] ?? [])->where('in', 'path')->pluck('name')->sort()->values()->all();

            expect($documented)->toBe($declared, "{$key} path parameters");
        }
    });
});

describe('document quality', function () {
    it('has unique operation ids, tags and error responses on every operation', function () {
        $ids = [];
        foreach (specOperations() as $key => $op) {
            expect($op)->toHaveKeys(['operationId', 'tags', 'summary', 'responses'], $key);
            $ids[] = $op['operationId'];
            expect($op['responses'])->toHaveKey('429');
        }
        expect($ids)->toBe(array_values(array_unique($ids)));
    });

    it('resolves every reference', function () {
        $spec = OpenApi::spec();
        $walk = function ($node) use (&$walk, $spec) {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if ($k === '$ref') {
                    $target = $spec;
                    foreach (explode('/', ltrim($v, '#/')) as $part) {
                        expect(is_array($target) && array_key_exists($part, $target))->toBeTrue("dangling reference {$v}");
                        $target = $target[$part];
                    }
                } else {
                    $walk($v);
                }
            }
        };
        $walk($spec);
    });

    it('lists the entitlement keys the server actually has', function () {
        expect(array_keys(OpenApi::spec()['components']['schemas']['EntitlementMap']['properties']))->toEqualCanonicalizing(Entitlement::keys());
    });

    it('declares every error code the API can return somewhere in its description', function () {
        $text = json_encode(OpenApi::spec());
        foreach (['unauthenticated', 'entitlement_required', 'validation_failed', 'not_found', 'rate_limited', 'upgrade_required', 'maintenance', 'cursor_expired', 'idempotency_key_reused'] as $code) {
            expect($text)->toContain($code);
        }
    });
});

describe('contract', function () {
    beforeEach(function () {
        seedPosture();
        seedFoods();
        seedBadges();
        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->user = User::factory()->onboarded(['plan_started_on' => '2026-10-05'])->create();
        $this->h = fn () => apiAs($this->user);
        $this->fits = function (string $schema, mixed $value) {
            expect(OpenApi::check($schema, $value))->toBe([], "response does not match schema {$schema}");
        };
    });
    afterEach(fn () => Carbon::setTestNow());

    it('keeps user, profile and membership responses inside their schemas', function () {
        $this->fits->__invoke('User', $this->getJson('/api/v1/me', ($this->h)())->json('data'));
        $this->fits->__invoke('Profile', $this->getJson('/api/v1/me/profile', ($this->h)())->json('data'));
        $this->fits->__invoke('Settings', $this->getJson('/api/v1/me/settings', ($this->h)())->json('data'));
        $this->fits->__invoke('Membership', $this->getJson('/api/v1/membership', ($this->h)())->json('data'));
        foreach ($this->getJson('/api/v1/membership/plans')->json('data') as $plan) {
            $this->fits->__invoke('MembershipPlan', $plan);
        }
        $this->fits->__invoke('AppConfig', $this->getJson('/api/v1/app/config', ['X-App-Platform' => 'android', 'X-App-Version' => '1.0.0'])->json('data'));
        $this->fits->__invoke('NotificationPrefs', $this->getJson('/api/v1/me/notifications', ($this->h)())->json('data'));
        $this->fits->__invoke('Dashboard', $this->getJson('/api/v1/dashboard', ($this->h)())->json('data'));
    });

    it('keeps sign-in responses inside their schema', function () {
        $login = $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password', 'device' => deviceBlock()]);
        $this->fits->__invoke('AuthResult', $login->json('data'));
        $this->fits->__invoke('Token', $this->postJson('/api/v1/auth/refresh', [], bearer($login->json('data.token.access_token')))->json('data.token'));
        foreach ($this->getJson('/api/v1/auth/sessions', ($this->h)())->json('data') as $device) {
            $this->fits->__invoke('Device', $device);
        }
        $this->fits->__invoke('Error', $this->getJson('/api/v1/me')->json());
    });

    it('keeps library, programs and content inside their schemas', function () {
        foreach ($this->getJson('/api/v1/exercises?per_page=100', ($this->h)())->json('data') as $exercise) {
            $this->fits->__invoke('Exercise', $exercise);
        }
        $programs = $this->getJson('/api/v1/programs', ($this->h)())->json('data');
        $this->fits->__invoke('Program', $programs[0]);
        $this->fits->__invoke('ProgramDetail', $this->getJson("/api/v1/programs/{$programs[0]['id']}", ($this->h)())->json('data'));
        $this->fits->__invoke('ContentManifest', $this->getJson('/api/v1/content/manifest', ($this->h)())->json('data'));
        $this->fits->__invoke('ContentSnapshot', $this->getJson('/api/v1/content/exercises', ($this->h)())->json());
        $this->fits->__invoke('ContentChanges', $this->getJson('/api/v1/content/changes?after=0&limit=50', ($this->h)())->json());
        foreach ($this->getJson('/api/v1/content/badges', ($this->h)())->json('data') as $badge) {
            expect($badge)->toHaveKeys(['slug', 'name', 'tier']);
        }
        $this->fits->__invoke('PlanDay', $this->getJson('/api/v1/plan/days/1', ($this->h)())->json('data'));
        $this->fits->__invoke('PostureRule', $this->getJson('/api/v1/posture/rules/'.PostureRule::first()->exercise->slug, ($this->h)())->json('data'));
    });

    it('keeps workout responses inside their schemas', function () {
        $session = $this->postJson('/api/v1/workout-sessions', [], ($this->h)())->assertCreated()->json('data');
        $this->fits->__invoke('WorkoutSession', $session);
        $set = $this->postJson("/api/v1/workout-sessions/{$session['id']}/sets", ['session_exercise_id' => $session['exercises'][0]['id'], 'set_number' => 1, 'weight_kg' => 60, 'reps' => 8], ($this->h)())->json('data');
        $this->fits->__invoke('SessionSet', $set['set']);
        $this->putJson("/api/v1/workout-sessions/{$session['id']}/state", ['state' => ['idx' => 0, 'setIdx' => 1, 'phase' => 'rest', 'rest_ends_at' => now()->addMinute()->toIso8601String()]], ($this->h)())->assertOk();
        $this->fits->__invoke('WorkoutSession', $this->getJson("/api/v1/workout-sessions/{$session['id']}", ($this->h)())->json('data'));
        $this->fits->__invoke('Achievements', $this->getJson('/api/v1/achievements', ($this->h)())->json('data'));
    });

    it('keeps nutrition responses inside their schemas', function () {
        $food = Food::whereNull('user_id')->first();
        $this->fits->__invoke('Food', $this->getJson("/api/v1/foods/{$food->id}", ($this->h)())->json('data'));
        $log = $this->postJson('/api/v1/food-logs', ['food_id' => $food->id, 'logged_on' => '2026-10-12', 'meal' => 'lunch', 'quantity' => 150, 'unit' => 'grams'], ($this->h)())->assertCreated()->json('data');
        $this->fits->__invoke('FoodLog', $log['log']);
        $this->fits->__invoke('DayNutrition', $log['day']);
        $this->fits->__invoke('DayNutrition', $this->getJson('/api/v1/nutrition/day?date=2026-10-12', ($this->h)())->json('data'));
        $this->fits->__invoke('Macros', $this->postJson('/api/v1/nutrition/calculate', ['food_id' => $food->id, 'quantity' => 150, 'unit' => 'grams'], ($this->h)())->json('data.macros'));
        $this->fits->__invoke('Habits', $this->getJson('/api/v1/habits', ($this->h)())->json('data'));
        foreach ($this->getJson('/api/v1/progress/measurements', ($this->h)())->json('data') as $m) {
            $this->fits->__invoke('Measurement', $m);
        }
    });

    it('the schema checker itself catches a wrong type, a missing field and a bad enum', function () {
        expect(OpenApi::check('Token', ['access_token' => 5, 'token_type' => 'Basic', 'expires_at' => 'x']))->not->toBe([])
            ->and(implode(' ', OpenApi::check('Token', ['access_token' => 'a', 'token_type' => 'Bearer', 'expires_at' => 'x'])))->toContain('refresh_after: required but missing')
            ->and(implode(' ', OpenApi::check('Token', ['access_token' => 5, 'token_type' => 'Bearer', 'expires_at' => 'x', 'refresh_after' => 'y'])))->toContain('expected string')
            ->and(implode(' ', OpenApi::check('Token', ['access_token' => 'a', 'token_type' => 'Basic', 'expires_at' => 'x', 'refresh_after' => 'y'])))->toContain('is not one of');
    });
});
