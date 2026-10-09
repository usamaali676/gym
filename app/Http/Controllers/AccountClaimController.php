<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClaimAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountClaimController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->is_guest) {
            return redirect()->route('profile.edit');
        }

        return view('account.claim');
    }

    /** Turn the guest into a registered account in place, so no data has to move. */
    public function store(ClaimAccountRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_guest' => false,
        ])->save();

        return redirect()->route('dashboard')->with('status', 'Account saved. You can now sign in from any device.');
    }
}
