<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /** Replay operations queued offline, in order. Returns one result per operation. */
    public function batch(Request $request, SyncBatchService $sync): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.SyncBatchService::MAX_OPERATIONS],
            'operations.*.op_id' => ['required', 'uuid', 'distinct'],
            'operations.*.type' => ['required', 'string', 'max:40'],
            'operations.*.payload' => ['present', 'array'],
            'operations.*.client_created_at' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => [
            'results' => $sync->run($request->user(), $data['operations']),
            'server_time' => now()->toIso8601String(),
        ]]);
    }
}
