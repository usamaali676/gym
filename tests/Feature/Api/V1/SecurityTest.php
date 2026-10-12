<?php

use App\Models\Device;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\Entitlement;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** A concrete URL for a route: every {parameter} filled with a plausible value. */
function concreteUrl(LaravelRoute $route): string
{
    return preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($route) {
        $constraint = $route->wheres[$m[1]] ?? null;

        return match (true) {
            $m[1] === 'collection' => 'exercises',
            $constraint !== null && str_contains($constraint, 'a-z') => 'some-slug',
            $constraint === null && in_array($m[1], ['exercise', 'slug'], true) => 'some-slug',
            default => '1',
        };
    }, '/'.$route->uri());
}

/** @return list<LaravelRoute> */
function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/') && str_starts_with((string) $r->getName(), 'v1.'))->values()->all();
}

function call($test, LaravelRoute $route, array $headers = [])
{
    $method = strtolower(array_values(array_diff($route->methods(), ['HEAD']))[0]);

    return $method === 'get' ? $test->getJson(concreteUrl($route), $headers) : $test->{$method.'Json'}(concreteUrl($route), [], $headers);
}

const PUBLIC_ROUTES = [
    'v1.app.config', 'v1.membership.plans', 'v1.media.exercise', 'v1.media.banner',
    'v1.auth.register', 'v1.auth.login', 'v1.auth.guest', 'v1.auth.forgot-password', 'v1.auth.reset-password',
];

describe('authentication coverage', function () {
    it('keeps every route behind a token except a short, reviewed list', function () {
        $open = collect(apiRoutes())->filter(fn ($r) => ! collect(app('router')->gatherRouteMiddleware($r))->contains(fn ($m) => is_string($m) && str_ends_with($m, 'Authenticate:sanctum')))
            ->map(fn ($r) => $r->getName())->sort()->values()->all();

        expect($open)->toBe(collect(PUBLIC_ROUTES)->sort()->values()->all(), 'a route lost its authentication, or a new public route needs review');
    });

    it('answers 401 in the standard envelope to every protected route without a token', function () {
        $checked = 0;
        foreach (apiRoutes() as $route) {
            if (in_array($route->getName(), PUBLIC_ROUTES, true)) {
                continue;
            }
            $response = call($this, $route);
            expect($response->status())->toBe(401, "{$route->getName()} without a token");
            expect($response->json('error.code'))->toBe('unauthenticated');
            $checked++;
        }
        expect($checked)->toBeGreaterThan(90);
    });

    it('rejects a garbage token on every protected route', function () {
        foreach (apiRoutes() as $route) {
            if (in_array($route->getName(), PUBLIC_ROUTES, true)) {
                continue;
            }
            expect(call($this, $route, bearer('ipt_1|'.str_repeat('z', 40)))->status())->toBe(401, $route->getName());
        }
    });

    it('never accepts a website session cookie in place of a token', function () {
        $user = User::factory()->onboarded()->create();

        foreach (apiRoutes() as $route) {
            if (in_array($route->getName(), PUBLIC_ROUTES, true)) {
                continue;
            }
            expect(call($this->actingAs($user), $route)->status())->toBe(401, $route->getName());
        }
    });
});

describe('robustness', function () {
    it('never answers a read with a server error, for a fully onboarded member', function () {
        $member = User::factory()->onboarded()->create();

        foreach (apiRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || in_array($route->getName(), PUBLIC_ROUTES, true)) {
                continue;
            }
            $status = call($this, $route, apiAs($member))->status();
            expect($status)->toBeLessThan(500, "{$route->getName()} answered {$status}");
        }
    });
});

describe('entitlement coverage', function () {
    it('refuses every entitled route to a member whose plan lacks the feature', function () {
        $member = User::factory()->onboarded()->create();
        $free = MembershipPlan::default();
        $free->entitlements = array_fill_keys(Entitlement::keys(), false);
        $free->save();

        $checked = [];
        foreach (apiRoutes() as $route) {
            $gate = collect(app('router')->gatherRouteMiddleware($route))->first(fn ($m) => is_string($m) && str_contains($m, 'EnsureEntitled:'));
            if (! $gate) {
                continue;
            }
            $key = explode(':', $gate, 2)[1];
            $response = call($this, $route, apiAs($member));

            expect($response->status())->toBe(403, "{$route->getName()} should be closed without '{$key}'");
            expect($response->json('error.code'))->toBe('entitlement_required');
            expect($response->json('error.details.required_entitlement'))->toBe($key);
            $checked[$key] = ($checked[$key] ?? 0) + 1;
        }

        expect(array_keys($checked))->toEqualCanonicalizing(['workouts', 'nutrition', 'analytics', 'progress_photos', 'posture_coach'])
            ->and($checked['workouts'])->toBeGreaterThan(10)->and($checked['nutrition'])->toBeGreaterThan(15);
    });

    it('opens those same routes again for a plan that includes the feature', function () {
        $member = User::factory()->onboarded()->create();

        foreach (apiRoutes() as $route) {
            $gate = collect(app('router')->gatherRouteMiddleware($route))->first(fn ($m) => is_string($m) && str_contains($m, 'EnsureEntitled:'));
            if ($gate) {
                expect(call($this, $route, apiAs($member))->status())->not->toBe(403, $route->getName());
            }
        }
    });

    it('cannot be skipped by sending an entitlement claim of its own', function () {
        $member = User::factory()->onboarded()->create();
        $free = MembershipPlan::default();
        $free->entitlements = array_fill_keys(Entitlement::keys(), false);
        $free->save();

        $this->getJson('/api/v1/nutrition/targets', apiAs($member) + ['X-Entitlements' => 'nutrition', 'X-Plan' => 'premium'])->assertForbidden();
        $this->postJson('/api/v1/food-logs', ['plan' => 'premium', 'entitlements' => ['nutrition' => true]], apiAs($member))->assertForbidden();
    });
});

describe('mass assignment and privilege', function () {
    it('ignores role, status and XP fields on register, profile and onboarding', function () {
        seedPlan();
        $r = $this->postJson('/api/v1/auth/register', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'Str0ngPassw0rd', 'role' => 'admin', 'status' => 'active', 'xp_total' => 9999, 'is_guest' => true, 'device' => deviceBlock()])->assertCreated();
        $user = User::where('email', 'eve@example.com')->firstOrFail();
        expect($user->isAdmin())->toBeFalse()->and($user->xp_total)->toBe(0)->and($user->is_guest)->toBeFalse();

        $this->postJson('/api/v1/me/onboarding', bodyProfilePayload() + ['role' => 'admin', 'xp_total' => 5000], bearer($r->json('data.token.access_token')))->assertOk();
        expect($user->fresh()->isAdmin())->toBeFalse()->and($user->fresh()->xp_total)->toBe(0);
    });

    it('never exposes the admin panel to a token', function () {
        $admin = User::factory()->onboarded()->admin()->create();

        $this->getJson('/admin', apiAs($admin))->assertStatus(401);
        $this->getJson('/api/v1/admin/users', apiAs($admin))->assertNotFound();
    });
});

describe('information leaks', function () {
    it('never returns secrets, hashes or internal columns anywhere', function () {
        seedPosture();
        seedFoods();
        $user = User::factory()->onboarded()->create();
        $headers = apiAs($user);
        $forbidden = ['"password"', 'remember_token', '"push_token"', 'push_token_hash', 'two_factor', 'api_key', 'client_secret', 'private_key', 'suspended_reason', '"personal_access_token'];

        $bodies = [];
        foreach (['/api/v1/me', '/api/v1/me/profile', '/api/v1/me/settings', '/api/v1/membership', '/api/v1/membership/plans', '/api/v1/dashboard', '/api/v1/auth/sessions', '/api/v1/me/notifications', '/api/v1/app/config', '/api/v1/programs', '/api/v1/exercises', '/api/v1/achievements'] as $url) {
            $bodies[$url] = $this->getJson($url, $headers)->getContent();
        }
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device' => deviceBlock()])->getContent();
        $bodies['login'] = preg_replace('/"access_token":"[^"]+"/', '"access_token":"-"', $login);

        foreach ($bodies as $where => $body) {
            foreach ($forbidden as $needle) {
                expect($body)->not->toContain($needle, "{$where} leaked {$needle}");
            }
        }
        expect($bodies['/api/v1/app/config'])->not->toContain($user->email);
    });

    it('does not reveal stack traces or file paths in server errors', function () {
        $user = User::factory()->onboarded()->create();
        Route::middleware(['api.v1', 'auth:sanctum'])->get('/api/v1/_boom', fn () => throw new RuntimeException('database password is hunter2 at /var/www/secret.php'));

        $response = $this->getJson('/api/v1/_boom', apiAs($user))->assertStatus(500);

        expect($response->getContent())->not->toContain('hunter2')->not->toContain('/var/www')->not->toContain('RuntimeException')->not->toContain('trace');
        expect($response->json('error.code'))->toBe('server_error')->and($response->json('error.request_id'))->not->toBeEmpty();
    });

    it('marks private answers as uncacheable and unsniffable', function () {
        $user = User::factory()->onboarded()->create();

        $response = $this->getJson('/api/v1/me', apiAs($user));

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
        // errors too
        expect($this->getJson('/api/v1/me')->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('advertises rate limits on signed-in calls', function () {
        $user = User::factory()->onboarded()->create();

        $response = $this->getJson('/api/v1/me', apiAs($user));

        expect($response->headers->get('X-RateLimit-Limit'))->not->toBeNull();
    });
});

describe('cross-member isolation by id', function () {
    it('answers 404 for every id-addressed route when the id belongs to someone else', function () {
        seedPosture();
        seedFoods();
        $alice = User::factory()->onboarded()->create();
        $bob = User::factory()->onboarded()->create();
        $h = apiAs($bob);
        $a = apiAs($alice);

        $session = $this->postJson('/api/v1/workout-sessions', [], $a)->json('data');
        $food = $this->postJson('/api/v1/foods', ['name' => 'Alice shake', 'diet_type' => 'vegan', 'kcal_100g' => 100, 'protein_100g' => 20, 'carbs_100g' => 5, 'fat_100g' => 0.5], $a)->json('data');
        $log = $this->postJson('/api/v1/food-logs', ['food_id' => $food['id'], 'logged_on' => now()->toDateString(), 'meal' => 'lunch', 'quantity' => 100, 'unit' => 'grams'], $a)->json('data.log');
        $meal = $this->postJson('/api/v1/saved-meals', ['name' => 'Mine', 'items' => [['food_id' => $food['id'], 'grams' => 100]]], $a)->json('data');
        $measurement = $this->postJson('/api/v1/progress/measurements', ['measured_on' => now()->toDateString(), 'weight_kg' => 70], $a)->json('data.measurement');
        $deviceRow = (new Device)->forceFill(['user_id' => $alice->id, 'installation_id' => str_repeat('a', 20), 'platform' => 'android']);
        $deviceRow->save();
        $device = $deviceRow->id;

        $sessionId = $session['id'];
        $exerciseRow = $session['exercises'][0]['id'];
        $attempts = [
            ['getJson', "/api/v1/workout-sessions/{$sessionId}"], ['getJson', "/api/v1/workout-sessions/{$sessionId}/summary"],
            ['postJson', "/api/v1/workout-sessions/{$sessionId}/finish"], ['postJson', "/api/v1/workout-sessions/{$sessionId}/abandon"],
            ['postJson', "/api/v1/workout-sessions/{$sessionId}/exercises/{$exerciseRow}/skip"], ['getJson', "/api/v1/workout-sessions/{$sessionId}/exercises/{$exerciseRow}/alternatives"],
            ['getJson', "/api/v1/foods/{$food['id']}"], ['deleteJson', "/api/v1/foods/{$food['id']}"], ['putJson', "/api/v1/foods/{$food['id']}/favorite"],
            ['patchJson', "/api/v1/food-logs/{$log['id']}", ['quantity' => 5, 'unit' => 'grams']], ['deleteJson', "/api/v1/food-logs/{$log['id']}"],
            ['deleteJson', "/api/v1/saved-meals/{$meal['id']}"], ['postJson', "/api/v1/saved-meals/{$meal['id']}/log", ['meal' => 'lunch']],
            ['deleteJson', "/api/v1/progress/measurements/{$measurement['id']}"], ['deleteJson', "/api/v1/auth/sessions/{$device}"],
        ];

        foreach ($attempts as $attempt) {
            [$verb, $url] = $attempt;
            $response = $verb === 'getJson' ? $this->getJson($url, $h) : $this->{$verb}($url, $attempt[2] ?? [], $h);
            expect($response->status())->toBe(404, "{$verb} {$url} by another member");
            expect($response->json('error.code'))->toBe('not_found');
        }
        // and nothing changed for Alice
        expect($this->getJson("/api/v1/workout-sessions/{$sessionId}", $a)->json('data.status'))->toBe('in_progress');
    });
});
