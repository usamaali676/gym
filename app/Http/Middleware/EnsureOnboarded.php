<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sends users who have not finished the profile wizard to it. */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isOnboarded()) {
            if ($request->expectsJson()) {
                abort(409, 'Complete onboarding first.');
            }

            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
