<?php

namespace App\Http\Middleware;

use App\Services\Membership\EntitlementService;
use App\Support\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `entitled:nutrition` — the signed-in user's plan must grant the entitlement.
 * JSON clients get the API error envelope (403 entitlement_required); web pages get the "membership required" screen.
 */
class EnsureEntitled
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $entitlements = $this->entitlements->for($user);
        if ($entitlements->allows($key)) {
            return $next($request);
        }

        $label = Entitlement::tryFrom($key)?->label() ?? $key;

        if ($request->is('api/*') || $request->expectsJson()) {
            // /api/v1 gets the shared envelope; the older same-origin JSON API keeps a minimal body.
            if ($request->is('api/v1/*')) {
                $this->entitlements->authorize($user, $key);
            }

            return response()->json(['message' => 'Your membership does not include this feature.', 'required_entitlement' => $key], 403);
        }

        return response()->view('membership.required', ['feature' => $label, 'plan' => $entitlements->plan], 403);
    }
}
