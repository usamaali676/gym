<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BodyProfileRequest;
use App\Http\Requests\ClaimAccountRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\V1\ProfileResource;
use App\Http\Resources\V1\UserResource;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Api\MobileTokenService;
use App\Services\ProfileService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('profile'));
    }

    /** Name and email. Guests have no email until they claim the account. */
    public function update(ProfileUpdateRequest $request): UserResource
    {
        $request->user()->fill($request->validated())->save();

        return new UserResource($request->user()->load('profile'));
    }

    public function updatePassword(Request $request, MobileTokenService $tokens): JsonResponse
    {
        $user = $request->user();
        abort_if($user->is_guest, 403);

        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', Password::defaults(), 'different:current_password'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();

        // Every other signed-in device must sign in again with the new password.
        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->getKey())->delete();
        $user->devices()->where('personal_access_token_id', '!=', $current->getKey())->get()->each->clearPushToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Permanently delete the account and everything it owns (photos, history, tokens). */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_guest) {
            $request->validate(['confirm' => ['required', 'in:DELETE']]);
        } else {
            $request->validate(['password' => ['required', 'string', 'current_password:sanctum']]);
        }

        $user->delete(); // cascades rows, removes photos and tokens (see User::booted)

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Turn a guest into a registered account in place, so nothing has to move. */
    public function claim(ClaimAccountRequest $request): UserResource
    {
        $request->user()->forceFill([
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_guest' => false,
        ])->save();

        return new UserResource($request->user()->load('profile'));
    }

    public function profile(Request $request): ProfileResource
    {
        $profile = $request->user()->profile ?? throw ApiException::onboardingRequired();

        return new ProfileResource($profile);
    }

    public function updateProfile(BodyProfileRequest $request, ProfileService $profiles): ProfileResource
    {
        $profile = $profiles->save($request->user(), $request->validated());

        return new ProfileResource($profile);
    }

    /** First-run wizard: same rules as the web onboarding, and it marks the account as onboarded. */
    public function onboarding(BodyProfileRequest $request, ProfileService $profiles): UserResource
    {
        $profiles->save($request->user(), $request->validated(), completeOnboarding: true);

        return new UserResource($request->user()->fresh()->load('profile'));
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload($request->user())]);
    }

    public function updateSettings(SettingsRequest $request, SettingsService $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->profile, 409);

        $settings->update($user, $request->validated());

        return response()->json(['data' => $this->settingsPayload($user->fresh())]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(User $user): array
    {
        $profile = $user->profile ?? throw ApiException::onboardingRequired();
        $n = $user->notificationSetting ?? new NotificationSetting;

        $time = fn (?string $v) => $v ? substr($v, 0, 5) : null;

        return [
            'sound_enabled' => (bool) $profile->sound_enabled,
            'voice_coach' => (bool) $profile->voice_coach,
            'leaderboard_opt_in' => (bool) $profile->leaderboard_opt_in,
            'ai_coach_opt_in' => (bool) $profile->ai_coach_opt_in,
            'public_name' => $profile->public_name,
            'water_goal_ml' => $profile->waterGoalMl(),
            'sleep_goal_hours' => (float) $profile->sleep_goal_hours,
            'creatine_dose_g' => (float) $profile->creatine_dose_g,
            'notifications' => [
                'workout_reminder_at' => $time($n->workout_reminder_at),
                'creatine_reminder_at' => $time($n->creatine_reminder_at),
                'sleep_reminder_at' => $time($n->sleep_reminder_at),
                'water_reminders' => (bool) $n->water_reminders,
                'water_interval_minutes' => (int) ($n->water_interval_minutes ?: 90),
                'email_digest' => (bool) $n->email_digest,
                'digest_day' => (int) ($n->digest_day ?: 7),
            ],
        ];
    }
}
