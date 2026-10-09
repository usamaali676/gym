<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        // The weekly split on the landing page comes from the seeded plan, never from the template.
        $plan = Plan::default()?->load('days');

        return view('welcome', ['plan' => $plan, 'guestMode' => config('ironpulse.guest_mode')]);
    }
}
