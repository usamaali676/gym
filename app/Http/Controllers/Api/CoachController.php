<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Coach\CoachService;
use App\Services\Coach\CoachUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoachController extends Controller
{
    public function __construct(private readonly CoachService $coach) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->coach->enabled(), 404);
        $data = $request->validate(['message' => ['required', 'string', 'max:'.CoachService::MAX_INPUT]]);
        $text = trim(preg_replace('/\s+\n/', "\n", $data['message']));
        if ($text === '') {
            return response()->json(['message' => 'Type a question first.'], 422);
        }

        try {
            $result = $this->coach->ask($request->user(), $text);
        } catch (CoachUnavailable $e) {
            return response()->json(['message' => $e->getMessage(), 'remaining' => $this->coach->remaining($request->user())], $e->status);
        }

        return response()->json([
            'user' => ['id' => $result['user']->id, 'role' => 'user', 'content' => $result['user']->content],
            'reply' => ['id' => $result['reply']->id, 'role' => 'assistant', 'content' => $result['reply']->content],
            'remaining' => $result['remaining'],
            'truncated' => $result['truncated'],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless($this->coach->enabled(), 404);
        $this->coach->clear($request->user());

        return response()->json(['remaining' => $this->coach->remaining($request->user())]);
    }
}
