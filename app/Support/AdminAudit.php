<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/** Every change an admin makes is written to the application log with who did it and what changed. */
class AdminAudit
{
    /** @param array<string, mixed> $context */
    public static function record(string $action, array $context = []): void
    {
        Log::info("admin.{$action}", ['admin_id' => auth()->id()] + $context);
    }
}
