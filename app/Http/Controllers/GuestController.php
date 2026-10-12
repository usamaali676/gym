<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class GuestController extends Controller
{
    /** Start a guest session: a real user row with no email/password that can be claimed later. */
    public function store(): RedirectResponse
    {
        abort_unless(config('ironpulse.guest_mode'), 404);

        $guest = new User(['name' => 'Guest']);
        $guest->is_guest = true;
        $guest->save();

        Auth::login($guest, remember: true);

        return redirect()->route('onboarding.show');
    }
}
