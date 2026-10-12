<?php

namespace App\Services\Push;

use App\Models\Device;

/** Used while no Firebase project is configured: nothing leaves the server and callers are told it was skipped. */
class NullPushProvider implements PushProvider
{
    public function send(Device $device, string $title, string $body, array $data): string
    {
        return self::SKIPPED;
    }
}
