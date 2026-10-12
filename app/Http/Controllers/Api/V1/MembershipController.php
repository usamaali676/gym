<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MembershipPlanResource;
use App\Http\Resources\V1\SubscriptionResource;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipService;
use App\Services\Membership\PaymentVerificationException;
use App\Services\Membership\PaymentVerifierRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MembershipController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly MembershipService $memberships,
        private readonly PaymentVerifierRegistry $verifiers,
    ) {}

    /** Plans an admin has published, cheapest first. Public so the paywall can show them before sign-in. */
    public function plans(): AnonymousResourceCollection
    {
        return MembershipPlanResource::collection(
            MembershipPlan::query()->published()->orderBy('sort_order')->orderBy('price_minor')->get()
        );
    }

    /** The caller's current plan, subscription and what it allows. Cheap enough to call before any sensitive action. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summary($request->user())]);
    }

    public function entitlements(Request $request): JsonResponse
    {
        $e = $this->entitlements->for($request->user());

        return response()->json(['data' => [
            'entitlements' => $e->map(),
            'limits' => (object) $e->limits(),
            'plan_slug' => $e->plan?->slug,
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /** The app forwards a store receipt; the store (through the verifier) decides what it is worth. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:apple,google'],
            'product_id' => ['required', 'string', 'max:190'],
            'receipt' => ['required', 'string', 'max:20000'],
            'transaction_id' => ['nullable', 'string', 'max:190'],
        ]);

        $this->applyOne($request->user(), $data);

        return response()->json(['data' => $this->summary($request->user())]);
    }

    /** Re-check purchases the store reports for this Apple / Google account (new phone, reinstall). */
    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchases' => ['required', 'array', 'min:1', 'max:10'],
            'purchases.*.provider' => ['required', 'in:apple,google'],
            'purchases.*.product_id' => ['required', 'string', 'max:190'],
            'purchases.*.receipt' => ['required', 'string', 'max:20000'],
            'purchases.*.transaction_id' => ['nullable', 'string', 'max:190'],
        ]);

        $results = [];
        foreach ($data['purchases'] as $purchase) {
            try {
                $this->applyOne($request->user(), $purchase);
                $results[] = ['product_id' => $purchase['product_id'], 'status' => 'applied'];
            } catch (ApiException $e) {
                $results[] = ['product_id' => $purchase['product_id'], 'status' => 'rejected', 'code' => $e->errorCode];
            }
        }

        return response()->json(['data' => ['results' => $results, ...$this->summary($request->user())]]);
    }

    /** @param  array<string, mixed>  $payload */
    private function applyOne(User $user, array $payload): void
    {
        $verifier = $this->verifiers->for($payload['provider']);

        try {
            $purchase = $verifier->verify($payload);
        } catch (PaymentVerificationException $e) {
            throw $e->retryable
                ? new ApiException('payments_unreachable', 'The store could not be reached. Try again shortly.', 503)
                : new ApiException('purchase_invalid', 'The store did not confirm this purchase.', 422);
        }

        // The store's answer is authoritative; a client that names a different product is wrong or lying.
        if ($purchase->productId !== $payload['product_id']) {
            throw new ApiException('product_mismatch', 'The receipt is for a different product.', 422);
        }

        $this->memberships->applyPurchase($user, $purchase);
    }

    /** @return array<string, mixed> */
    private function summary(User $user): array
    {
        $e = $this->entitlements->for($user);

        return [
            'plan' => $e->plan ? new MembershipPlanResource($e->plan) : null,
            'subscription' => $e->subscription ? new SubscriptionResource($e->subscription) : null,
            'entitlements' => $e->map(),
            'limits' => (object) $e->limits(),
            'payments' => ['available_providers' => $this->verifiers->available()],
            'checked_at' => now()->toIso8601String(),
            // Apps may cache this, but must re-fetch before sensitive actions and after this many seconds.
            'revalidate_after_seconds' => 300,
        ];
    }
}
