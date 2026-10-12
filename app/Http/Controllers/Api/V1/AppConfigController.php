<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Public bootstrap data the app reads before sign-in: server time, auth parameters and feature switches. */
class AppConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'api_version' => 'v1',
            'server_time' => now()->toIso8601String(),
            'auth' => [
                'guest_mode' => (bool) config('ironpulse.guest_mode'),
                'token_ttl_days' => (int) config('ironpulse.mobile.token_ttl_days'),
                'refresh_window_days' => (int) config('ironpulse.mobile.refresh_window_days'),
            ],
            'features' => [
                'coach' => (bool) config('ironpulse.coach.enabled'),
            ],
        ]]);
    }
}
