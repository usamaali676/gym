<?php

namespace App\Services\App;

use App\Models\AppVersionPolicy;

/**
 * Decides whether an app build may talk to the API. This keeps old builds from breaking against a newer API; it is
 * not a security control (a modified client can lie about its version), so every real permission check stays on the server.
 */
class AppVersionService
{
    public const OK = 'ok';

    public const UPDATE_RECOMMENDED = 'update_recommended';

    public const UPDATE_REQUIRED = 'update_required';

    /** Normalise "1.2.3+45" / "v1.2" to a comparable "1.2.3" string, or null if it is not a version. */
    public static function normalise(?string $version): ?string
    {
        if ($version === null || ! preg_match('/^v?(\d+(?:\.\d+){0,3})(?:[+-].*)?$/', trim($version), $m)) {
            return null;
        }

        return $m[1];
    }

    public function status(AppVersionPolicy $policy, string $version): string
    {
        $v = self::normalise($version);
        if ($v === null) {
            return self::OK; // unknown format: do not lock people out over a formatting difference
        }

        if (version_compare($v, self::normalise($policy->min_supported) ?? '0', '<')) {
            return self::UPDATE_REQUIRED;
        }

        $recommended = self::normalise($policy->recommended);
        if ($recommended !== null && version_compare($v, $recommended, '<')) {
            return self::UPDATE_RECOMMENDED;
        }

        return self::OK;
    }

    /** @return array<string, mixed> */
    public function describe(AppVersionPolicy $policy, ?string $version = null): array
    {
        return [
            'platform' => $policy->platform,
            'status' => $version ? $this->status($policy, $version) : null,
            'min_supported' => $policy->min_supported,
            'recommended' => $policy->recommended,
            'latest' => $policy->latest,
            'store_url' => $policy->store_url,
            'release_notes' => $policy->release_notes,
        ];
    }

    /** @return array{enabled: bool, message: ?string, ends_at: ?string} */
    public function maintenance(?AppVersionPolicy $policy): array
    {
        $active = $policy?->maintenanceActive() ?? false;

        return [
            'enabled' => $active,
            'message' => $active ? $policy->maintenance_message : null,
            'ends_at' => $active ? $policy->maintenance_ends_at?->toIso8601String() : null,
        ];
    }
}
