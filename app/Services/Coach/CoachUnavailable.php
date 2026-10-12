<?php

namespace App\Services\Coach;

use RuntimeException;

/** A coach problem whose message is written for the person using the app (never a raw provider error). */
class CoachUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503)
    {
        parent::__construct($message);
    }
}
