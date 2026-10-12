<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes retries safe. When a request carries an `Idempotency-Key` header, the first answer is stored; sending the
 * same key again (a dropped connection, an offline queue replay) returns that answer without running the action twice.
 *
 *  - same key, same request      -> the stored response, with `Idempotent-Replayed: true`
 *  - same key, different request -> 422 idempotency_key_reused (a client bug, never silently accepted)
 *  - same key while the first is still running -> 409 request_in_progress
 *
 * Only responses below 500 are kept: a server error is retried for real. Keys expire after 24 hours.
 */
class Idempotent
{
    private const MAX_STORED_BYTES = 65536;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();

        if ($key === null || $user === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) {
            throw new ApiException('validation_failed', 'Idempotency-Key must be 8 to 80 letters, digits, dashes or underscores.', 422);
        }

        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent().'|'.json_encode($request->query()));

        $existing = IdempotencyKey::query()->where('user_id', $user->id)->where('key', $key)->first();
        if ($existing && $existing->expires_at->isPast()) {
            $existing->delete();
            $existing = null;
        }

        if ($existing) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $record = IdempotencyKey::create([
                'user_id' => $user->id, 'key' => $key, 'fingerprint' => $fingerprint,
                'expires_at' => now()->addHours((int) config('ironpulse.mobile.idempotency_hours')),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with the same key arriving twice at once.
            return $this->replay(IdempotencyKey::query()->where('user_id', $user->id)->where('key', $key)->firstOrFail(), $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete(); // nothing was stored; let a retry run for real
            throw $e;
        }

        $body = $response->getContent();
        if ($response->getStatusCode() < 500 && $response instanceof JsonResponse && strlen((string) $body) <= self::MAX_STORED_BYTES) {
            $record->update(['response_code' => $response->getStatusCode(), 'response_body' => $body]);
        } else {
            $record->delete();
        }

        return $response;
    }

    private function replay(IdempotencyKey $record, string $fingerprint): Response
    {
        if (! hash_equals($record->fingerprint, $fingerprint)) {
            throw new ApiException('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.', 422);
        }
        if ($record->response_code === null) {
            throw new ApiException('request_in_progress', 'The first attempt of this request is still running.', 409, [], ['Retry-After' => '2']);
        }

        return response($record->response_body, $record->response_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }
}
