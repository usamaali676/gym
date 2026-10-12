<?php

namespace App\Services\Membership;

/**
 * Confirms a purchase with the store that sold it. The mobile app only forwards the opaque receipt / purchase
 * token; what it was worth, whether it is valid and when it ends is decided by the store's answer here, never by
 * anything the client claims (prices and "payment succeeded" flags are ignored).
 */
interface PaymentVerifier
{
    /** apple | google */
    public function provider(): string;

    /** False until the store credentials this verifier needs are configured on the server. */
    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $payload  the client's receipt / token fields
     *
     * @throws PaymentVerificationException when the store rejects the purchase or cannot be reached
     */
    public function verify(array $payload): VerifiedPurchase;
}
