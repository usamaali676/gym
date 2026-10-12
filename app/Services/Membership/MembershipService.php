<?php

namespace App\Services\Membership;

use App\Exceptions\ApiException;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Changes to who is on which plan. Every change leaves a row in `subscription_events`. */
class MembershipService
{
    /**
     * Give a user a plan by hand (admin or promotional access).
     *
     * @param  CarbonInterface|null  $endsAt  null = no expiry
     */
    public function grant(User $user, MembershipPlan $plan, ?CarbonInterface $endsAt, string $source = 'admin', ?User $by = null, ?string $notes = null): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $endsAt, $source, $by, $notes) {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'membership_plan_id' => $plan->id,
                'status' => 'active',
                'source' => $source,
                'started_at' => now(),
                'current_period_ends_at' => $endsAt,
                'ends_at' => $endsAt,
                'auto_renew' => false,
                'granted_by' => $by?->id,
                'notes' => $notes,
            ]);

            $this->log($subscription, "{$source}.granted", $by ? ['by' => $by->id, 'plan' => $plan->slug, 'ends_at' => $endsAt?->toIso8601String()] : ['plan' => $plan->slug]);

            return $subscription;
        });
    }

    /** Move the end date (null = no expiry). Reactivates a lapsed but not revoked subscription. */
    public function extend(Subscription $subscription, ?CarbonInterface $endsAt, ?User $by = null): Subscription
    {
        $subscription->forceFill([
            'status' => 'active',
            'current_period_ends_at' => $endsAt,
            'ends_at' => $endsAt,
            'canceled_at' => null,
        ])->save();

        $this->log($subscription, 'admin.extended', ['by' => $by?->id, 'ends_at' => $endsAt?->toIso8601String()]);

        return $subscription;
    }

    /** Take access away immediately. */
    public function revoke(Subscription $subscription, ?User $by = null, ?string $reason = null): Subscription
    {
        $subscription->forceFill(['status' => 'revoked', 'ends_at' => now(), 'auto_renew' => false])->save();

        $this->log($subscription, 'admin.revoked', ['by' => $by?->id, 'reason' => $reason]);

        return $subscription;
    }

    /**
     * Apply what the store said about a purchase. Idempotent: the same transaction updates one subscription.
     *
     * @throws ApiException when the product matches no plan or the purchase belongs to another account
     */
    public function applyPurchase(User $user, VerifiedPurchase $purchase): Subscription
    {
        $plan = MembershipPlan::query()->where("store_product_ids->{$purchase->provider}", $purchase->productId)->first()
            ?? throw new ApiException('unknown_product', 'This product is not part of any membership plan.', 422);

        return DB::transaction(function () use ($user, $purchase, $plan) {
            $existing = Subscription::query()->where('source', $purchase->provider)->where('external_id', $purchase->externalId)->lockForUpdate()->first();

            // One purchase belongs to one account; a receipt cannot be shared.
            if ($existing && $existing->user_id !== $user->id) {
                throw ApiException::conflict('purchase_already_claimed', 'This purchase is already linked to another account.');
            }

            $ended = in_array($purchase->state, ['expired', 'revoked'], true);
            $status = match ($purchase->state) {
                'trialing' => 'trialing',
                'canceled' => 'canceled',
                'expired' => 'expired',
                'revoked' => 'revoked',
                default => 'active',
            };
            // Grace days keep access briefly after a failed renewal, but never after a revocation/refund.
            $end = $purchase->periodEndsAt?->addDays($plan->grace_days);

            $subscription = $existing ?? new Subscription(['user_id' => $user->id, 'source' => $purchase->provider, 'external_id' => $purchase->externalId]);
            $subscription->forceFill([
                'membership_plan_id' => $plan->id,
                'status' => $status,
                'started_at' => $subscription->started_at ?? $purchase->startedAt,
                'trial_ends_at' => $purchase->trialEndsAt,
                'current_period_ends_at' => $purchase->periodEndsAt,
                'ends_at' => $purchase->state === 'revoked' ? now() : ($ended ? ($purchase->periodEndsAt ?? now()) : $end),
                'auto_renew' => $purchase->autoRenew,
                'canceled_at' => $status === 'canceled' ? ($subscription->canceled_at ?? CarbonImmutable::now()) : null,
            ])->save();

            $this->log($subscription, "{$purchase->provider}.verified", ['state' => $purchase->state, 'product' => $purchase->productId], $purchase->provider, $purchase->eventId);

            return $subscription;
        });
    }

    /** Flip lapsed subscriptions to `expired` (display/reporting only; access is already decided by `ends_at`). */
    public function markLapsed(): int
    {
        return Subscription::query()->whereIn('status', ['trialing', 'active', 'canceled'])
            ->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(Subscription $subscription, string $type, array $payload = [], ?string $provider = null, ?string $eventId = null): void
    {
        // A repeated store notification (same event id) is recorded once.
        if ($eventId !== null && SubscriptionEvent::query()->where('external_event_id', $eventId)->exists()) {
            return;
        }

        SubscriptionEvent::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'type' => $type,
            'provider' => $provider ?? $subscription->source,
            'external_event_id' => $eventId,
            'payload' => $payload,
        ]);
    }
}
