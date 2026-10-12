<?php

namespace Tests\Support;

use App\Services\Membership\PaymentVerificationException;
use App\Services\Membership\PaymentVerifier;
use App\Services\Membership\VerifiedPurchase;
use Carbon\CarbonImmutable;

/** Stands in for Apple / Google in tests. The receipt string selects the outcome, like a real store answering. */
class FakePaymentVerifier implements PaymentVerifier
{
    public bool $configured = true;

    /** Make the "store" report a different product than the client claimed. */
    public ?string $reportProduct = null;

    public function __construct(public string $provider = 'apple') {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function verify(array $payload): VerifiedPurchase
    {
        $receipt = (string) $payload['receipt'];

        if ($receipt === 'invalid') {
            throw new PaymentVerificationException('rejected by store');
        }
        if ($receipt === 'unreachable') {
            throw new PaymentVerificationException('timeout', retryable: true);
        }

        // "good:<transaction>:<state>:<days>" e.g. good:tx-1:active:30
        [, $transaction, $state, $days] = array_pad(explode(':', $receipt), 4, null);
        $now = CarbonImmutable::now();

        return new VerifiedPurchase(
            provider: $this->provider,
            productId: $this->reportProduct ?? $payload['product_id'],
            externalId: $transaction ?? 'tx-default',
            state: $state ?? 'active',
            startedAt: $now,
            periodEndsAt: $now->addDays((int) ($days ?? 30)),
            trialEndsAt: ($state ?? '') === 'trialing' ? $now->addDays(7) : null,
            autoRenew: ($state ?? 'active') === 'active',
            eventId: null,
        );
    }
}
