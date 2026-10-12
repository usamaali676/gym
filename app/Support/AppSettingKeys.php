<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The settings an admin can change without shipping an app update. The list is closed on purpose: a setting only
 * exists here when the apps actually read it, and none of them can weaken a security rule (authentication,
 * entitlements and payments are never configurable from here).
 */
final class AppSettingKeys
{
    /** @return array<string, array{type: string, label: string, hint: string, default: mixed}> */
    public static function all(): array
    {
        return [
            'support.email' => ['type' => 'string', 'label' => 'Support email', 'hint' => 'Shown on the Help screen.', 'default' => null],
            'support.url' => ['type' => 'url', 'label' => 'Support website', 'hint' => 'Opens from the Help screen.', 'default' => null],
            'legal.terms_url' => ['type' => 'url', 'label' => 'Terms of service link', 'hint' => 'Required by the app stores.', 'default' => null],
            'legal.privacy_url' => ['type' => 'url', 'label' => 'Privacy policy link', 'hint' => 'Required by the app stores.', 'default' => null],
            'home.tagline' => ['type' => 'string', 'label' => 'Dashboard tagline', 'hint' => 'One line under the greeting.', 'default' => null],
            'features.leaderboard' => ['type' => 'bool', 'label' => 'Show the leaderboard', 'hint' => 'Hides the screen in the apps when off.', 'default' => true],
            'features.help_center' => ['type' => 'bool', 'label' => 'Show the help centre', 'hint' => 'Hides the screen in the apps when off.', 'default' => true],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** Current value of a setting, falling back to its registered default. */
    public static function get(string $key): mixed
    {
        return AppSetting::query()->where('key', $key)->first()?->value ?? (self::all()[$key]['default'] ?? null);
    }

    /** @return array<string, mixed> every public setting with defaults filled in */
    public static function publicValues(): array
    {
        $stored = AppSetting::query()->where('is_public', true)->pluck('value', 'key');

        return collect(self::all())->mapWithKeys(fn (array $def, string $key) => [$key => $stored->has($key) ? $stored[$key] : $def['default']])->all();
    }
}
