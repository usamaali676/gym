<?php

namespace App\Services\Push;

use App\Models\Device;
use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Sends push notifications on behalf of the app, honouring each member's choices:
 *  - the category must be switched on (opt-in/out lives on the server so every device agrees)
 *  - not during the member's quiet hours (their own timezone)
 *  - lock-screen text is generic unless the member allowed details; the real wording is delivered in `data.detail`
 *    and shown inside the app after it is opened
 */
class PushService
{
    public const CATEGORIES = ['workout_reminders', 'membership', 'achievements', 'announcements', 'nutrition'];

    public function __construct(private readonly PushProvider $provider) {}

    /** @return array{sent: int, skipped: int, failed: int} */
    public function notify(User $user, PushMessage $message, ?CarbonInterface $now = null): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $settings = NotificationSetting::where('user_id', $user->id)->first();

        if (! $this->wants($settings, $message->category) || $this->inQuietHours($user, $settings, $now ?? now())) {
            $result['skipped']++;

            return $result;
        }

        [$title, $body] = ($settings?->lock_screen_details)
            ? [$message->title, $message->body]
            : ['IronPulse', 'You have a new update. Open the app to see it.'];
        $data = $message->data + ['category' => $message->category, 'detail_title' => $message->title, 'detail_body' => $message->body];
        // Without the lock-screen opt-in the real text is NOT put in the push at all; the app fetches it after opening.
        if (! $settings?->lock_screen_details) {
            unset($data['detail_title'], $data['detail_body']);
        }

        foreach (Device::where('user_id', $user->id)->whereNotNull('personal_access_token_id')->whereNotNull('push_token')->get() as $device) {
            $outcome = $this->provider->send($device, $title, $body, $data);
            match ($outcome) {
                PushProvider::SENT => $result['sent']++,
                PushProvider::INVALID_TOKEN => $this->dropToken($device, $result),
                PushProvider::SKIPPED => $result['skipped']++,
                default => $result['failed']++,
            };
        }

        return $result;
    }

    /** @param  array{sent: int, skipped: int, failed: int}  $result */
    private function dropToken(Device $device, array &$result): void
    {
        $device->clearPushToken();
        $result['skipped']++;
    }

    private function wants(?NotificationSetting $settings, string $category): bool
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            return false;
        }
        $defaults = ['workout_reminders' => true, 'membership' => true, 'achievements' => true, 'announcements' => true, 'nutrition' => false];

        return (bool) ($settings?->{'push_'.$category} ?? $defaults[$category]);
    }

    private function inQuietHours(User $user, ?NotificationSetting $settings, CarbonInterface $now): bool
    {
        if (! $settings?->quiet_hours_start || ! $settings?->quiet_hours_end) {
            return false;
        }

        $local = $now->copy()->setTimezone($user->timezone())->format('H:i');
        $start = substr($settings->quiet_hours_start, 0, 5);
        $end = substr($settings->quiet_hours_end, 0, 5);

        // A window that crosses midnight (22:00 to 07:00) is "after the start OR before the end".
        return $start <= $end ? ($local >= $start && $local < $end) : ($local >= $start || $local < $end);
    }
}
