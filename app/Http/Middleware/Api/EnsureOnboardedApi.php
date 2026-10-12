<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API counterpart of EnsureOnboarded: a JSON 409 instead of a redirect. */
class EnsureOnboardedApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isOnboarded()) {
            throw ApiException::onboardingRequired();
        }

        return $next($request);
    }
}
