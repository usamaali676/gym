<?php

namespace Tests\Support;

use App\Models\Device;
use App\Services\Push\PushProvider;

/** Records what would have been pushed. Tokens that start with "dead" are reported as no longer valid. */
class FakePushProvider implements PushProvider
{
    /** @var list<array{token: string, title: string, body: string, data: array<string, mixed>}> */
    public array $sent = [];

    public function send(Device $device, string $title, string $body, array $data): string
    {
        if (str_starts_with((string) $device->push_token, 'dead')) {
            return self::INVALID_TOKEN;
        }
        $this->sent[] = ['token' => $device->push_token, 'title' => $title, 'body' => $body, 'data' => $data];

        return self::SENT;
    }
}
