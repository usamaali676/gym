<?php

namespace App\Services\Membership;

use Carbon\CarbonImmutable;

/** A store purchase after the backend (not the app) has confirmed it with Apple or Google. */
final class VerifiedPurchase
{
    public function __construct(
        public readonly string $provider,       // apple | google
        public readonly string $productId,
        public readonly string $externalId,     // original transaction id / purchase token id
        public readonly string $state,          // active | trialing | canceled | expired | revoked
        public readonly CarbonImmutable $startedAt,
        public readonly ?CarbonImmutable $periodEndsAt,
        public readonly ?CarbonImmutable $trialEndsAt = null,
        public readonly bool $autoRenew = false,
        public readonly ?string $eventId = null,
    ) {}
}
