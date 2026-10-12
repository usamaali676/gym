<?php

namespace App\Http\Requests\Api\V1\Concerns;

/** The `device` block every sign-in request carries. */
trait ValidatesDevice
{
    /** @return array<string, mixed> */
    protected function deviceRules(): array
    {
        return [
            'device' => ['required', 'array'],
            'device.installation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'device.platform' => ['required', 'in:android,ios'],
            'device.name' => ['nullable', 'string', 'max:120'],
            'device.app_version' => ['required', 'string', 'max:32'],
            'device.app_build' => ['nullable', 'string', 'max:32'],
            'device.os_version' => ['nullable', 'string', 'max:40'],
            'device.locale' => ['nullable', 'string', 'max:16'],
            'device.timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
