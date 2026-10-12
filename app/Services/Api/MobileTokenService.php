<?php

namespace App\Services\Api;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues, rotates and revokes the bearer tokens the mobile apps sign in with.
 * One token per app install; the plain text is returned once and only its SHA-256 hash is stored (Sanctum).
 */
class MobileTokenService
{
    /**
     * @param  array<string, mixed>  $device  validated device block (installation_id, platform, ...)
     * @return array{token: array<string, mixed>, device: Device}
     */
    public function issue(User $user, array $device): array
    {
        return DB::transaction(function () use ($user, $device) {
            $record = Device::query()->firstOrNew(['user_id' => $user->id, 'installation_id' => $device['installation_id']]);

            // Signing in again from the same install replaces its previous token.
            if ($record->exists && $record->personal_access_token_id) {
                PersonalAccessToken::query()->whereKey($record->personal_access_token_id)->delete();
            }

            $record->fill(array_intersect_key($device, array_flip(['platform', 'name', 'app_version', 'app_build', 'os_version', 'locale', 'timezone'])));
            $record->last_seen_at = now();

            $new = $user->createToken(
                name: $device['platform'].':'.substr($device['installation_id'], 0, 8),
                abilities: ['*'],
                expiresAt: now()->addDays(config('ironpulse.mobile.token_ttl_days')),
            );

            $record->personal_access_token_id = $new->accessToken->getKey();
            $record->user_id = $user->id;
            $record->save();

            $this->enforceDeviceLimit($user, keep: $record->id);

            return ['token' => $this->payload($new->plainTextToken, $new->accessToken), 'device' => $record];
        });
    }

    /**
     * Swap the caller's token for a fresh one with a new expiry.
     *
     * @return array{token: array<string, mixed>, device: ?Device}
     */
    public function rotate(User $user, PersonalAccessToken $current): array
    {
        return DB::transaction(function () use ($user, $current) {
            $device = Device::query()->where('user_id', $user->id)->where('personal_access_token_id', $current->getKey())->first();

            $new = $user->createToken($current->name, $current->abilities ?? ['*'], now()->addDays(config('ironpulse.mobile.token_ttl_days')));

            $device?->forceFill(['personal_access_token_id' => $new->accessToken->getKey(), 'last_seen_at' => now()])->save();
            $current->delete();

            return ['token' => $this->payload($new->plainTextToken, $new->accessToken), 'device' => $device];
        });
    }

    /** Sign one install out: revoke its token and stop pushing to it. */
    public function revokeDevice(Device $device): void
    {
        DB::transaction(function () use ($device) {
            $device->clearPushToken();
            if ($device->personal_access_token_id) {
                PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->delete();
            }
        });
    }

    public function revokeAll(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->devices()->get()->each->clearPushToken();
            $user->tokens()->delete();
        });
    }

    /** @return array<string, mixed> */
    public function payload(string $plainText, PersonalAccessToken $token): array
    {
        $expires = $token->expires_at;

        return [
            'access_token' => $plainText,
            'token_type' => 'Bearer',
            'expires_at' => $expires?->toIso8601String(),
            // The app should rotate the token once this moment has passed.
            'refresh_after' => $expires?->copy()->subDays(config('ironpulse.mobile.refresh_window_days'))->toIso8601String(),
        ];
    }

    /** Keep at most N signed-in installs; the least recently used one is signed out. */
    private function enforceDeviceLimit(User $user, int $keep): void
    {
        $max = (int) config('ironpulse.mobile.max_devices');

        $active = Device::query()->where('user_id', $user->id)->whereNotNull('personal_access_token_id')
            ->orderByRaw('COALESCE(last_seen_at, created_at) asc')->get();

        $overflow = $active->count() - $max;
        if ($overflow <= 0) {
            return;
        }

        $active->reject(fn (Device $d) => $d->id === $keep)->take($overflow)->each(fn (Device $d) => $this->revokeDevice($d));
    }
}
