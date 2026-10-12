<?php

namespace App\Models;

use App\Support\Entitlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    use HasFactory;

    public const INTERVALS = ['none', 'week', 'month', 'year', 'lifetime'];

    protected $fillable = [
        'slug', 'name', 'description', 'price_minor', 'currency', 'billing_interval', 'interval_count', 'duration_days',
        'trial_days', 'grace_days', 'auto_renews', 'features', 'entitlements', 'limits', 'store_product_ids',
        'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'duration_days' => 'integer',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'auto_renews' => 'boolean',
            'is_default' => 'boolean',
            'features' => 'array',
            'entitlements' => 'array',
            'limits' => 'array',
            'store_product_ids' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MembershipPlan $plan) {
            if ($plan->status === 'published' && ! $plan->published_at) {
                $plan->published_at = now();
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** The plan everyone without an effective subscription is on. */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public function grants(Entitlement|string $key): bool
    {
        $key = $key instanceof Entitlement ? $key->value : $key;

        return (bool) ($this->entitlements[$key] ?? false);
    }

    /** @return int|null  null = unlimited */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function isFree(): bool
    {
        return $this->price_minor === 0;
    }

    /** Human price for admin screens ("USD 9.99"). Apps format prices from the store, not from this. */
    public function priceLabel(): string
    {
        return $this->price_minor === 0 ? 'Free' : sprintf('%s %s', $this->currency, number_format($this->price_minor / 100, 2));
    }
}
