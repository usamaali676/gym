<?php

namespace App\Http\Middleware\Api;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps "last seen" fresh for the user and the device behind the token (at most one write per five minutes). */
class TouchDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user && $token && method_exists($token, 'getKey')) {
            $stale = now()->subMinutes(5);

            if (! $user->last_seen_at || $user->last_seen_at->lt($stale)) {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }

            Device::query()
                ->where('personal_access_token_id', $token->getKey())
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $stale))
                ->update(['last_seen_at' => now()]);
        }

        return $response;
    }
}
