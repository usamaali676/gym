<?php

namespace App\Support\Api;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Exceptions\MissingRateLimiterException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One error shape for the whole /api/v1 surface:
 *
 *   {"error": {"code": "validation_failed", "message": "...", "details": {...}, "request_id": "..."}}
 *
 * Internal exception messages and traces are never exposed.
 */
class ErrorRenderer
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/v1') || $request->is('api/v1/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) {
            return null;
        }

        $headers = [];
        $details = [];

        switch (true) {
            case $e instanceof ApiException:
                [$status, $code, $message, $details, $headers] = [$e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers];
                break;
            case $e instanceof ValidationException:
                [$status, $code, $message, $details] = [422, 'validation_failed', 'The given data was invalid.', $e->errors()];
                break;
            case $e instanceof AuthenticationException:
                [$status, $code, $message] = [401, 'unauthenticated', 'Authentication is required, or the token is no longer valid.'];
                break;
            case $e instanceof ThrottleRequestsException:
                $headers = $e->getHeaders();
                $details = isset($headers['Retry-After']) ? ['retry_after' => (int) $headers['Retry-After']] : [];
                [$status, $code, $message] = [429, 'rate_limited', 'Too many requests. Please slow down.'];
                break;
            case $e instanceof InvalidSignatureException:
                [$status, $code, $message] = [403, 'invalid_signature', 'This link is invalid or has expired.'];
                break;
            case $e instanceof NotFoundHttpException:
                [$status, $code, $message] = [404, 'not_found', 'The requested resource was not found.'];
                break;
            case $e instanceof MethodNotAllowedHttpException:
                $headers = $e->getHeaders();
                [$status, $code, $message] = [405, 'method_not_allowed', 'This method is not allowed for the resource.'];
                break;
            case $e instanceof HttpExceptionInterface:
                $status = $e->getStatusCode();
                $headers = $e->getHeaders();
                $code = match ($status) {
                    400 => 'bad_request', 403 => 'forbidden', 409 => 'conflict', 413 => 'payload_too_large',
                    415 => 'unsupported_media_type', 419 => 'session_expired', 503 => 'service_unavailable',
                    default => 'http_'.$status,
                };
                $message = $status === 403 ? 'You are not allowed to do that.' : ($e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error'));
                break;
            case $e instanceof MissingRateLimiterException:
            default:
                [$status, $code, $message] = [500, 'server_error', 'Something went wrong on our side.'];
                break;
        }

        $error = [
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
            'request_id' => $request->attributes->get('request_id'),
        ];

        return response()->json(['error' => $error], $status, $headers);
    }
}
