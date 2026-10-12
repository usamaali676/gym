<?php

namespace App\Support;

use App\Models\MembershipPlan;
use App\Models\Subscription;

/**
 * What one user may do right now.
 *
 * Access is the union of every plan that currently confers it: the default (free) plan is the floor, and each
 * effective subscription adds its plan's grants on top. Limits take the most generous value (null = unlimited).
 */
final class Entitlements
{
    /**
     * @param  list<MembershipPlan>  $plans  every plan that applies (floor first)
     * @param  MembershipPlan|null  $plan  the plan to show as "your plan"
     * @param  Subscription|null  $subscription  the subscription behind $plan, if any
     */
    public function __construct(
        public readonly array $plans,
        public readonly ?MembershipPlan $plan,
        public readonly ?Subscription $subscription,
    ) {}

    public function allows(Entitlement|string $key): bool
    {
        // No plan at all (a database without even the fallback row) means memberships are not configured:
        // keep the pre-membership behaviour rather than locking everyone out.
        if ($this->plans === []) {
            return true;
        }

        foreach ($this->plans as $plan) {
            if ($plan->grants($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return int|null  null = unlimited */
    public function limit(string $key): ?int
    {
        $best = null;
        foreach ($this->plans as $plan) {
            $limit = $plan->limit($key);
            if ($limit === null) {
                return null;
            }
            $best = max($best ?? 0, $limit);
        }

        return $this->plans === [] ? null : $best;
    }

    /** @return array<string, bool> */
    public function map(): array
    {
        return collect(Entitlement::cases())->mapWithKeys(fn (Entitlement $e) => [$e->value => $this->allows($e)])->all();
    }

    /** @return array<string, int|null> */
    public function limits(): array
    {
        return collect(array_keys(Entitlement::LIMITS))->mapWithKeys(fn (string $k) => [$k => $this->limit($k)])->all();
    }
}
