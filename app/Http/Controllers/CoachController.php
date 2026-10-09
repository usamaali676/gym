<?php

namespace App\Http\Controllers;

use App\Services\Coach\CoachService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoachController extends Controller
{
    public function __construct(private readonly CoachService $coach) {}

    public function index(Request $request): View
    {
        abort_unless($this->coach->enabled(), 404);
        $user = $request->user()->load('profile');

        $state = ! $this->coach->configured() ? 'unavailable' : ($this->coach->optedIn($user) ? 'ready' : 'opt_in');

        return view('coach.index', [
            'state' => $state,
            'config' => $state === 'ready' ? [
                'messages' => $this->coach->history($user)->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content])->all(),
                'remaining' => $this->coach->remaining($user),
                'limit' => $this->coach->limit(),
                'maxInput' => $this->coach::MAX_INPUT,
                'urls' => ['messages' => route('api.coach.messages.store'), 'clear' => route('api.coach.messages.destroy')],
                'suggestions' => [
                    "How should I progress this week's lifts?",
                    'What should I eat before and after training?',
                    'How do I fix a stalled bench press?',
                    "I'm sore: train or rest?",
                ],
            ] : null,
        ]);
    }

    public function optIn(Request $request): RedirectResponse
    {
        abort_unless($this->coach->enabled(), 404);
        $request->user()->profile()->update(['ai_coach_opt_in' => true]);

        return redirect()->route('coach.index')->with('status', 'The coach is on. You can switch it off any time.');
    }

    /** Switching off also deletes the saved conversation. */
    public function optOut(Request $request): RedirectResponse
    {
        abort_unless($this->coach->enabled(), 404);
        $user = $request->user();
        $user->profile()->update(['ai_coach_opt_in' => false]);
        $this->coach->clear($user);

        return redirect()->route('coach.index')->with('status', 'The coach is off and your conversation was deleted.');
    }
}
