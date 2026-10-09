<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostureSessionRequest;
use App\Services\PostureSessionService;
use App\Services\XpService;
use Illuminate\Http\JsonResponse;

class PostureSessionController extends Controller
{
    public function store(StorePostureSessionRequest $request, PostureSessionService $sessions, XpService $xp): JsonResponse
    {
        $user = $request->user();
        $result = $sessions->record($user, $request->validated());
        $user->refresh();

        return response()->json([
            'id' => $result['session']->id,
            'created' => $result['created'],
            'xp' => $result['xp'],
            'level' => $xp->progress($user->xp_total) + ['xp_total' => $user->xp_total],
        ], $result['created'] ? 201 : 200);
    }
}
