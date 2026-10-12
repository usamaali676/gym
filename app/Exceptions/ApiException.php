<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A domain error the mobile API reports with a stable machine-readable code.
 * Rendered as {"error": {"code", "message", "details", "request_id"}} by App\Support\Api\ErrorRenderer.
 */
class ApiException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function suspended(?string $reason = null): self
    {
        return new self('account_suspended', 'This account has been suspended.', 403, array_filter(['reason' => $reason]));
    }

    public static function onboardingRequired(): self
    {
        return new self('onboarding_required', 'Complete your profile before using this feature.', 409);
    }

    /** @param  array<string, mixed>  $details */
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }
}
