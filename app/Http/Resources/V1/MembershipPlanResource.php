<?php

namespace App\Http\Resources\V1;

use App\Models\MembershipPlan;
use App\Support\Entitlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as shown on the paywall. Prices here are for display only: the app shows the store's own localized
 * price for the matching product id, and the backend never accepts a price from the client.
 *
 * @mixin MembershipPlan
 */
class MembershipPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => (bool) $this->is_default,
            'price' => [
                'amount_minor' => $this->price_minor,
                'currency' => $this->currency,
                'display' => $this->priceLabel(),
            ],
            'billing' => [
                'interval' => $this->billing_interval,
                'interval_count' => $this->interval_count,
                'duration_days' => $this->duration_days,
                'trial_days' => $this->trial_days,
                'auto_renews' => $this->auto_renews,
            ],
            'features' => $this->features ?? [],
            'entitlements' => collect(Entitlement::keys())->mapWithKeys(fn (string $k) => [$k => $this->grants($k)])->all(),
            'limits' => collect(array_keys(Entitlement::LIMITS))->mapWithKeys(fn (string $k) => [$k => $this->limit($k)])->all(),
            'store_product_ids' => (object) ($this->store_product_ids ?? []),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
