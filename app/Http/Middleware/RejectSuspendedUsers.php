<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A suspended account is signed out of the website on its next request (the mobile API checks the same flag). */
class RejectSuspendedUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'This account has been suspended.'], 403);
            }

            return redirect()->route('login')->withErrors(['email' => 'This account has been suspended.']);
        }

        return $next($request);
    }
}
