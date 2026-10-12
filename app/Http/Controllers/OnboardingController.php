<?php

namespace App\Http\Controllers;

use App\Http\Requests\BodyProfileRequest;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isOnboarded()) {
            return redirect()->route('profile.edit');
        }

        return view('onboarding.show', ['user' => $user, 'profile' => $user->profile]);
    }

    public function store(BodyProfileRequest $request, ProfileService $profiles): RedirectResponse
    {
        $profiles->save($request->user(), $request->validated(), completeOnboarding: true);

        return redirect()->route('dashboard')->with('status', 'You are all set. Time to train.');
    }
}
