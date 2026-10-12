<?php

namespace Database\Factories;

use App\Models\MembershipPlan;
use App\Support\Entitlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MembershipPlan> */
class MembershipPlanFactory extends Factory
{
    protected $model = MembershipPlan::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
            'price_minor' => 999,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 0,
            'auto_renews' => true,
            'features' => ['Everything in Free'],
            'entitlements' => array_fill_keys(Entitlement::keys(), true),
            'limits' => [],
            'is_default' => false,
            'status' => 'published',
            'sort_order' => 10,
        ];
    }

    /** Grants only the listed keys. */
    public function granting(Entitlement ...$keys): static
    {
        return $this->state(fn () => ['entitlements' => collect($keys)->mapWithKeys(fn (Entitlement $k) => [$k->value => true])->all()]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'published_at' => null]);
    }
}
