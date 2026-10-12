<?php

namespace App\Services\Membership;

use RuntimeException;

class PaymentVerificationException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
