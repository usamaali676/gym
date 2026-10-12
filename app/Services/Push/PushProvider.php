<?php

namespace App\Services\Push;

use App\Models\Device;

interface PushProvider
{
    public const SENT = 'sent';

    public const INVALID_TOKEN = 'invalid_token';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** @return 'sent'|'invalid_token'|'failed'|'skipped' */
    public function send(Device $device, string $title, string $body, array $data): string;
}
