<?php

namespace App\Services\Membership;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Container\Container;

/** Looks up the verifier for a store. Stores without credentials report the feature as unavailable instead of pretending. */
class PaymentVerifierRegistry
{
    public function __construct(private readonly Container $container) {}

    /** @return array<string, class-string<PaymentVerifier>> */
    private function map(): array
    {
        return config('ironpulse.payments.verifiers', []);
    }

    public function for(string $provider): PaymentVerifier
    {
        $class = $this->map()[$provider] ?? null;
        $verifier = $class ? $this->container->make($class) : null;

        if (! $verifier || ! $verifier->isConfigured()) {
            throw new ApiException('payments_unavailable', 'Purchases for this store are not enabled on this server.', 503);
        }

        return $verifier;
    }

    /** @return list<string> stores that can verify purchases right now */
    public function available(): array
    {
        return collect($this->map())
            ->filter(fn (string $class) => $this->container->make($class)->isConfigured())
            ->keys()->values()->all();
    }
}
