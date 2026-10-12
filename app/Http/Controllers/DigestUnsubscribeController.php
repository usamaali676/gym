<?php

namespace App\Http\Controllers;

use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unsubscribe from the link in every digest email. The signed URL is the proof of identity.
 * GET only asks for confirmation (mail scanners follow links); POST, which mail apps use for one-click
 * unsubscribe, does the work.
 */
class DigestUnsubscribeController extends Controller
{
    public function confirm(Request $request, int $user): View
    {
        return view('auth.digest-unsubscribe', ['action' => $request->fullUrl()]);
    }

    public function unsubscribe(int $user): View
    {
        NotificationSetting::where('user_id', $user)->update(['email_digest' => false]);

        return view('auth.digest-unsubscribed');
    }
}
