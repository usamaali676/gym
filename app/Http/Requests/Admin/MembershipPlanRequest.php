<?php

namespace App\Http\Requests\Admin;

use App\Models\MembershipPlan;
use App\Support\Entitlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
            'currency' => strtoupper((string) $this->input('currency', 'USD')),
            'auto_renews' => $this->boolean('auto_renews'),
            'is_default' => $this->boolean('is_default'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var MembershipPlan|null $plan */
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:80', Rule::unique('membership_plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'price' => ['required', 'numeric', 'between:0,100000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'billing_interval' => ['required', Rule::in(MembershipPlan::INTERVALS)],
            'interval_count' => ['required', 'integer', 'between:1,12'],
            'duration_days' => ['nullable', 'integer', 'between:1,3650'],
            'trial_days' => ['required', 'integer', 'between:0,365'],
            'grace_days' => ['required', 'integer', 'between:0,30'],
            'auto_renews' => ['boolean'],
            'features' => ['nullable', 'string', 'max:1500'],
            'entitlements' => ['nullable', 'array'],
            'entitlements.*' => ['boolean'],
            'limits' => ['nullable', 'array'],
            'limits.*' => ['nullable', 'integer', 'between:0,100000'],
            'store_product_ids' => ['nullable', 'array'],
            'store_product_ids.apple' => ['nullable', 'string', 'max:190'],
            'store_product_ids.google' => ['nullable', 'string', 'max:190'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'is_default' => ['boolean'],
        ];
    }

    /** The validated input shaped for the model: price in minor units, bullets as a list, only known keys. */
    /** @return array<string, mixed> */
    public function planAttributes(): array
    {
        $data = $this->validated();

        $granted = collect(Entitlement::keys())->mapWithKeys(fn (string $k) => [$k => (bool) ($data['entitlements'][$k] ?? false)])->all();
        $limits = collect(array_keys(Entitlement::LIMITS))
            ->mapWithKeys(fn (string $k) => [$k => isset($data['limits'][$k]) && $data['limits'][$k] !== '' ? (int) $data['limits'][$k] : null])
            ->filter(fn ($v) => $v !== null)->all();
        $features = collect(preg_split('/\R/', (string) ($data['features'] ?? '')))->map(fn ($l) => trim((string) $l))->filter()->take(8)->map(fn ($l) => Str::limit($l, 120, ''))->values()->all();
        $products = array_filter(['apple' => trim((string) ($data['store_product_ids']['apple'] ?? '')), 'google' => trim((string) ($data['store_product_ids']['google'] ?? ''))]);

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'price_minor' => (int) round($data['price'] * 100),
            'currency' => $data['currency'],
            'billing_interval' => $data['billing_interval'],
            'interval_count' => $data['interval_count'],
            'duration_days' => $data['duration_days'] ?? null,
            'trial_days' => $data['trial_days'],
            'grace_days' => $data['grace_days'],
            'auto_renews' => $data['auto_renews'],
            'features' => $features,
            'entitlements' => $granted,
            'limits' => $limits,
            'store_product_ids' => $products,
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }
}
