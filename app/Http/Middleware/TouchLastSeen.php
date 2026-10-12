<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records that a signed-in user is still around, at most once an hour, without touching updated_at. */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subHour()))) {
            User::whereKey($user->id)->update(['last_seen_at' => now()]);
            $user->last_seen_at = now();
            $user->syncOriginalAttribute('last_seen_at');
        }

        return $next($request);
    }
}
