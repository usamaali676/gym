<?php

namespace App\Http\Controllers;

use App\Http\Requests\BodyProfileRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\SettingsRequest;
use App\Models\NotificationSetting;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
            'profile' => $user->profile,
            'notifications' => $user->notificationSetting ?? new NotificationSetting,
        ]);
    }

    /** Account details: name and email. */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        return back()->with('status', 'Account details updated.');
    }

    /** Body, goal and training profile. */
    public function body(BodyProfileRequest $request, ProfileService $profiles): RedirectResponse
    {
        $profiles->save($request->user(), $request->validated());

        return back()->with('status', 'Profile updated.');
    }

    /** Preferences, goals and reminder times. */
    public function settings(SettingsRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $profileKeys = ['sound_enabled', 'voice_coach', 'leaderboard_opt_in', 'ai_coach_opt_in', 'public_name', 'water_goal_ml', 'sleep_goal_hours', 'creatine_dose_g'];
        $notificationKeys = ['workout_reminder_at', 'creatine_reminder_at', 'sleep_reminder_at', 'water_reminders', 'water_interval_minutes', 'browser_notifications', 'email_digest', 'digest_day'];

        // Unchecked checkboxes are not sent; this form posts a hidden 0 first, so booleans are always present.
        $user->profile->fill(array_intersect_key($data, array_flip($profileKeys)))->save();
        NotificationSetting::updateOrCreate(
            ['user_id' => $user->id],
            array_intersect_key($data, array_flip($notificationKeys)),
        );

        return back()->with('status', 'Settings saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->is_guest) {
            $request->validateWithBag('userDeletion', ['confirm' => ['required', 'in:DELETE']]);
        } else {
            $request->validateWithBag('userDeletion', ['password' => ['required', 'current_password']]);
        }

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
