<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Membership\MembershipService;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Who is on which plan: grant, extend or revoke access by hand (support cases, promotions). */
class SubscriptionController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));

        $subscriptions = Subscription::query()->with(['user:id,name,email,is_guest', 'plan:id,name,slug'])
            ->when($status === 'effective', fn ($query) => $query->effective())
            ->when(in_array($status, Subscription::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.memberships.subscriptions', ['subscriptions' => $subscriptions, 'status' => $status, 'q' => $q]);
    }

    public function grant(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:membership_plans,id'],
            'ends_on' => ['nullable', 'date', 'after:today'],
            'source' => ['required', 'in:admin,promo'],
            'notes' => ['nullable', 'string', 'max:190'],
        ]);

        $plan = MembershipPlan::findOrFail($data['plan_id']);
        $endsAt = isset($data['ends_on']) ? now()->parse($data['ends_on'])->endOfDay() : null;

        $this->memberships->grant($user, $plan, $endsAt, $data['source'], $request->user(), $data['notes'] ?? null);
        AdminAudit::record('subscription.grant', ['user_id' => $user->id, 'plan' => $plan->slug, 'ends_at' => $endsAt?->toIso8601String()]);

        return back()->with('status', "{$plan->name} granted.");
    }

    public function extend(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate(['ends_on' => ['nullable', 'date', 'after:today']]);

        $this->memberships->extend($subscription, isset($data['ends_on']) ? now()->parse($data['ends_on'])->endOfDay() : null, $request->user());
        AdminAudit::record('subscription.extend', ['subscription_id' => $subscription->id]);

        return back()->with('status', 'Membership updated.');
    }

    public function revoke(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->memberships->revoke($subscription, $request->user(), $request->string('reason')->limit(190)->toString() ?: null);
        AdminAudit::record('subscription.revoke', ['subscription_id' => $subscription->id]);

        return back()->with('status', 'Membership revoked. Access ended immediately.');
    }
}
