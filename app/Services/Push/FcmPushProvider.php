<?php

namespace App\Services\Push;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1 API. iOS devices are reached through FCM too (upload the APNs auth key to the
 * Firebase project), so the server never talks to APNs directly.
 *
 * Needs FCM_PROJECT_ID and FCM_CREDENTIALS_PATH (a service-account JSON file kept outside the web root).
 * NOTE: written against Google's documented API and covered by HTTP-fake tests; it has not been run against a live
 * Firebase project from this repository's build environment.
 */
class FcmPushProvider implements PushProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function send(Device $device, string $title, string $body, array $data): string
    {
        $project = config('ironpulse.push.project_id');
        $token = $device->push_token;
        if (! $project || ! $token) {
            return self::SKIPPED;
        }

        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'ironpulse_general']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ];

        try {
            $response = Http::withToken($this->accessToken())->timeout(10)->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => $message]);
        } catch (\Throwable $e) {
            report($e);

            return self::FAILED;
        }

        if ($response->successful()) {
            return self::SENT;
        }

        // 404 UNREGISTERED / 400 INVALID_ARGUMENT on the token: the app was uninstalled or the token rotated.
        $status = $response->json('error.status');
        if (in_array($status, ['UNREGISTERED', 'NOT_FOUND'], true) || ($status === 'INVALID_ARGUMENT' && str_contains((string) $response->body(), 'registration token'))) {
            return self::INVALID_TOKEN;
        }

        return self::FAILED;
    }

    /** OAuth2 access token for the service account (cached for just under its one-hour life). */
    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', 3300, function () {
            $path = config('ironpulse.push.credentials_path');
            $credentials = $path && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($credentials) || empty($credentials['private_key']) || empty($credentials['client_email'])) {
                throw new RuntimeException('FCM service-account credentials are missing or unreadable.');
            }

            $now = time();
            $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64(json_encode(['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".$this->b64($signature),
            ])->throw();

            return (string) $response->json('access_token');
        });
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
