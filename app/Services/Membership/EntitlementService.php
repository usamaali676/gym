<?php

namespace App\Services\Membership;

use App\Exceptions\ApiException;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Entitlement;
use App\Support\Entitlements;
use Illuminate\Support\Collection;

/**
 * The single place that decides what a user may access. Always computed from the current database rows
 * (no long-lived cache), so an admin revoking or expiring a membership takes effect on the very next request.
 */
class EntitlementService
{
    public function for(User $user): Entitlements
    {
        $subscriptions = $this->effectiveSubscriptions($user);
        $floor = MembershipPlan::default();

        $plans = collect([$floor])->merge($subscriptions->pluck('plan'))->filter()->unique('id')->values()->all();

        // "Your plan": the most valuable plan among the subscriptions, else the free floor.
        $primary = $subscriptions->sortByDesc(fn (Subscription $s) => [$s->plan->price_minor, $s->ends_at?->timestamp ?? PHP_INT_MAX])->first();

        return new Entitlements($plans, $primary?->plan ?? $floor, $primary);
    }

    public function allows(User $user, Entitlement|string $key): bool
    {
        return $this->for($user)->allows($key);
    }

    /** Throws the 403 the mobile apps turn into a paywall; the server never relies on the client having hidden the button. */
    public function authorize(User $user, Entitlement|string $key): void
    {
        $entitlements = $this->for($user);
        if ($entitlements->allows($key)) {
            return;
        }

        $key = $key instanceof Entitlement ? $key->value : $key;

        throw new ApiException('entitlement_required', 'Your membership does not include this feature.', 403, [
            'required_entitlement' => $key,
            'current_plan' => $entitlements->plan?->slug,
        ]);
    }

    /** True while the user is below a plan's numeric limit (or the limit is unset). */
    public function withinLimit(User $user, string $limit, int $currentCount): bool
    {
        $max = $this->for($user)->limit($limit);

        return $max === null || $currentCount < $max;
    }

    /** @return Collection<int, Subscription> */
    public function effectiveSubscriptions(User $user): Collection
    {
        return Subscription::query()->where('user_id', $user->id)->effective()->with('plan')->get();
    }
}
