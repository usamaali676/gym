<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\FoodLog;
use App\Models\PostureSession;
use App\Models\ProgressPhoto;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Account administration. Admins can see that an account exists, how active it is and change its role, but not
 * read anyone's food, photos, measurements or messages: only counts are shown.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $filter = $request->query('filter');

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($filter === 'admins', fn ($query) => $query->where('role', 'admin'))
            ->when($filter === 'guests', fn ($query) => $query->where('is_guest', true))
            ->when($filter === 'members', fn ($query) => $query->where('is_guest', false))
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'q' => $q, 'filter' => $filter]);
    }

    public function show(User $user): View
    {
        return view('admin.users.show', [
            'account' => $user,
            'counts' => [
                'workouts' => WorkoutSession::where('user_id', $user->id)->where('status', 'completed')->count(),
                'posture' => PostureSession::where('user_id', $user->id)->count(),
                'food' => FoodLog::where('user_id', $user->id)->count(),
                'photos' => ProgressPhoto::where('user_id', $user->id)->count(),
            ],
            'lastAdmin' => $this->isLastAdmin($user),
        ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(Role::values())]]);

        if ($user->is($request->user())) {
            return back()->withErrors(['role' => 'You cannot change your own role. Ask another admin.']);
        }
        if ($data['role'] === 'admin' && ($user->is_guest || ! $user->email)) {
            return back()->withErrors(['role' => 'Guest accounts cannot be admins. They have no email or password to protect the account.']);
        }
        if ($data['role'] !== 'admin' && $this->isLastAdmin($user)) {
            return back()->withErrors(['role' => 'This is the last admin. Promote someone else first.']);
        }

        $before = $user->role->value;
        $user->forceFill(['role' => $data['role']])->save();
        AdminAudit::record('user.role', ['user_id' => $user->id, 'from' => $before, 'to' => $data['role']]);

        return back()->with('status', "Role changed to {$data['role']}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);

        if ($user->is($request->user())) {
            return back()->withErrors(['confirm' => 'You cannot delete your own account here. Use Profile instead.']);
        }
        if ($this->isLastAdmin($user)) {
            return back()->withErrors(['confirm' => 'You cannot delete the last admin.']);
        }
        $expected = $user->email ?: 'guest-'.$user->id;
        if (strcasecmp(trim($request->input('confirm')), $expected) !== 0) {
            return back()->withErrors(['confirm' => "Type {$expected} exactly to confirm."]);
        }

        AdminAudit::record('user.delete', ['user_id' => $user->id, 'guest' => $user->is_guest]);
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'Account and all of its data deleted.');
    }

    private function isLastAdmin(User $user): bool
    {
        return $user->role === Role::Admin && User::where('role', 'admin')->count() <= 1;
    }
}
