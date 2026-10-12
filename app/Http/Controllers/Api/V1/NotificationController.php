<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DeviceResource;
use App\Models\Device;
use App\Models\NotificationSetting;
use App\Services\Push\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Which remote notifications this member wants, and the push token of the phone they are using. */
class NotificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categories' => ['sometimes', 'array'],
            'categories.workout_reminders' => ['sometimes', 'boolean'],
            'categories.membership' => ['sometimes', 'boolean'],
            'categories.achievements' => ['sometimes', 'boolean'],
            'categories.announcements' => ['sometimes', 'boolean'],
            'categories.nutrition' => ['sometimes', 'boolean'],
            'quiet_hours' => ['sometimes', 'nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i', 'different:quiet_hours.start'],
            'lock_screen_details' => ['sometimes', 'boolean'],
        ]);

        $columns = [];
        foreach ($data['categories'] ?? [] as $category => $on) {
            $columns['push_'.$category] = (bool) $on;
        }
        if (array_key_exists('quiet_hours', $data)) {
            $columns['quiet_hours_start'] = $data['quiet_hours']['start'] ?? null;
            $columns['quiet_hours_end'] = $data['quiet_hours']['end'] ?? null;
        }
        if (isset($data['lock_screen_details'])) {
            $columns['lock_screen_details'] = (bool) $data['lock_screen_details'];
        }

        NotificationSetting::updateOrCreate(['user_id' => $request->user()->id], $columns);

        return response()->json(['data' => $this->payload($request)]);
    }

    /** Register (or rotate) this phone's push token. The app only does this once the server reports push as available. */
    public function registerPush(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:fcm'],
            'token' => ['required', 'string', 'min:20', 'max:4096'],
        ]);
        $device = $this->currentDevice($request);
        $hash = hash('sha256', $data['token']);

        // A token belongs to one install: if this phone was used by another account, that account stops receiving it.
        Device::where('push_token_hash', $hash)->where('id', '!=', $device->id)->get()->each->clearPushToken();

        $device->forceFill(['push_provider' => $data['provider'], 'push_token' => $data['token'], 'push_token_hash' => $hash])->save();

        return response()->json(['data' => ['device' => new DeviceResource($device)]]);
    }

    public function unregisterPush(Request $request): JsonResponse
    {
        $this->currentDevice($request)->clearPushToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function currentDevice(Request $request): Device
    {
        $tokenId = $request->user()->currentAccessToken()?->getKey();

        return Device::where('user_id', $request->user()->id)->where('personal_access_token_id', $tokenId)->first()
            ?? throw ApiException::notFound('This sign-in has no device record. Sign in again.');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $n = NotificationSetting::where('user_id', $request->user()->id)->first() ?? new NotificationSetting;
        $device = Device::where('user_id', $request->user()->id)->where('personal_access_token_id', $request->user()->currentAccessToken()?->getKey())->first();
        $defaults = ['workout_reminders' => true, 'membership' => true, 'achievements' => true, 'announcements' => true, 'nutrition' => false];

        return [
            'push_available' => (bool) config('ironpulse.push.enabled'),
            'push_registered' => $device?->push_token_hash !== null,
            'categories' => collect(PushService::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => (bool) ($n->{'push_'.$c} ?? $defaults[$c])])->all(),
            'quiet_hours' => $n->quiet_hours_start && $n->quiet_hours_end ? ['start' => substr($n->quiet_hours_start, 0, 5), 'end' => substr($n->quiet_hours_end, 0, 5)] : null,
            'lock_screen_details' => (bool) $n->lock_screen_details,
        ];
    }
}
